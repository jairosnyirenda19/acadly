<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Identity\ViewAs;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Writes the audit trail (docs/architecture/10-audit-trail.md).
 *
 * - A line is written in the caller's transaction: if the line cannot be
 *   written, the change does not happen. With no transaction open, the line
 *   is written in one of its own.
 * - Lines are chained: a transaction-level advisory lock gives every line
 *   the next number and the hash of the line before it.
 * - Personal values go to an encrypted side record. The line keeps its hash.
 */
final class Audit
{
    /** The advisory lock that orders the trail. One per deployment. */
    private const int LOCK = 7_310_431;

    public const array CHANNELS = ['console', 'portal'];

    public function __construct(
        private readonly AuditCatalogue $catalogue,
        private readonly Clock $clock,
        private readonly Encrypter $encrypter,
    ) {}

    /** Take a snapshot of a record before changing it. */
    public function watch(Auditable $subject): Watch
    {
        return Watch::of($subject);
    }

    /**
     * Write one line. Returns the line's id.
     *
     * @param  array<string, scalar|null>  $personal  personal values that are not a diff, such as an address someone typed
     * @param  array<string, scalar|null>  $meta
     */
    public function record(
        AuditAction $action,
        ?Auditable $subject = null,
        ?Diff $changes = null,
        Outcome $outcome = Outcome::Done,
        ?string $reason = null,
        ?string $refusal = null,
        ?Actor $actor = null,
        ?ScopeRef $at = null,
        ?string $aboutPersonId = null,
        array $personal = [],
        array $meta = [],
        ?JourneyLine $journey = null,
    ): string {
        if (! $this->catalogue->has($action->key())) {
            throw new LogicException("[{$action->key()}] is not an audit action in this build.");
        }

        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        if ($action->requiresReason() && $reason === null && $outcome === Outcome::Done) {
            throw new LogicException("[{$action->key()}] requires a reason.");
        }

        $changes ??= Diff::none();
        $actor ??= $this->currentActor();
        $now = Chain::time($this->clock->now());
        $id = Ids::next();
        $context = $this->requestContext();

        $line = [
            'id' => $id,
            'occurred_at' => $now,
            'division_id' => $at?->type === ScopeType::Division ? $at->id : null,
            'campus_id' => $at?->type === ScopeType::Campus ? $at->id : null,
            'module' => $this->catalogue->ownerOf($action),
            'action' => $action->key(),
            'outcome' => $outcome->value,
            'refusal' => $refusal,
            'actor_user_id' => $actor->userId,
            'actor_kind' => $actor->kind->value,
            'actor_label' => mb_substr($actor->label, 0, 255),
            'actor_roles' => $actor->roles,
            'acting_for_user_id' => $actor->actingForUserId,
            'support_grant_id' => null,
            ...$context,
            'subject_type' => $subject?->auditType(),
            'subject_id' => $subject?->auditId(),
            'subject_label' => $subject !== null ? mb_substr($subject->auditLabel(), 0, 255) : null,
            'about_person_id' => $aboutPersonId,
            'changes' => $changes->lineChanges(),
            'payload_id' => null,
            'payload_hash' => null,
            'reason' => $reason,
            'journey_ref' => $journey?->ref,
            'journey_step' => $journey?->step,
            'journey_outcome' => $journey?->outcome,
            'meta' => $at?->type === ScopeType::ClassGroup ? [...$meta, 'class_id' => $at->id] : $meta,
        ];

        $private = array_filter(['changes' => $changes->personal, 'values' => $personal], fn (array $part): bool => $part !== []);

        $write = function () use ($line, $private, $now): string {
            DB::select('select pg_advisory_xact_lock(?)', [self::LOCK]);

            if ($private !== []) {
                $plain = json_encode($private, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $line['payload_id'] = Ids::next();
                $line['payload_hash'] = hash('sha256', $plain);

                DB::table('audit_payloads')->insert([
                    'id' => $line['payload_id'],
                    'payload_hash' => $line['payload_hash'],
                    'ciphertext' => $this->encrypter->encryptString($plain),
                    'created_at' => $now,
                ]);
            }

            $last = DB::table('audit_events')->orderByDesc('seq')->first(['seq', 'hash']);
            $previous = is_string($last?->hash) ? $last->hash : Chain::GENESIS;
            $line['seq'] = (is_numeric($last?->seq) ? (int) $last->seq : 0) + 1;
            $hash = Chain::hash($previous, $line);

            $row = $line;
            foreach (Chain::JSON_COLUMNS as $column) {
                $row[$column] = json_encode($line[$column], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            DB::table('audit_events')->insert([...$row, 'prev_hash' => $previous, 'hash' => $hash, 'recorded_at' => $now]);

            return $line['id'];
        };

        return DB::transactionLevel() > 0 ? $write() : DB::transaction($write);
    }

    /** The account a request acts as, with the roles it holds now. */
    public function actorFor(?Authenticatable $who): Actor
    {
        if (! $who instanceof User) {
            return Actor::anonymous();
        }

        $now = $this->clock->now();

        /** @var list<string> $roles */
        $roles = DB::table('access_role_assignments as a')
            ->join('access_roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.user_id', $who->id)
            ->where('a.starts_at', '<=', $now)
            ->where(fn (Builder $q): Builder => $q->whereNull('a.ends_at')->orWhere('a.ends_at', '>', $now))
            ->orderBy('r.key')
            ->distinct()
            ->pluck('r.key')
            ->map(fn (mixed $key): string => is_string($key) ? $key : '')
            ->values()
            ->all();

        // View as (development only): an act done in a viewed session is the
        // administrator's, acting for the account viewed. It must never read
        // as that person's own, and no caller can forget it, because no
        // caller supplies it.
        $by = $this->viewingAs($who);

        if ($by !== null) {
            return new Actor(ActorKind::User, $by->id, "{$by->name}, viewing as {$who->name}", $roles, actingForUserId: $who->id);
        }

        return new Actor(ActorKind::User, $who->id, $who->name, $roles);
    }

    /** The administrator behind the current session, when it views as $who. */
    private function viewingAs(User $who): ?User
    {
        $request = app()->bound('request') ? app('request') : null;

        if (! $request instanceof Request || ! $request->hasSession()) {
            return null;
        }

        $by = $request->session()->get(ViewAs::BY);
        $current = Auth::guard('web')->id();

        return is_string($by) && $current === $who->id ? User::query()->find($by) : null;
    }

    private function currentActor(): Actor
    {
        return $this->request() === null ? Actor::system() : $this->actorFor(Auth::guard('web')->user());
    }

    /** @return array{session_ref: ?string, request_id: ?string, device_id: ?string, ip: ?string, user_agent: ?string, channel: string} */
    private function requestContext(): array
    {
        $request = $this->request();

        if ($request === null) {
            return ['session_ref' => null, 'request_id' => null, 'device_id' => null, 'ip' => null, 'user_agent' => null, 'channel' => 'job'];
        }

        $requestId = $request->attributes->get('acadly.request_id');

        if (! is_string($requestId)) {
            $given = $request->headers->get('X-Request-Id');
            $requestId = is_string($given) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $given) === 1 ? $given : Ids::next();
            $request->attributes->set('acadly.request_id', $requestId);
        }

        $channel = $request->headers->get('X-Acadly-Channel');
        $agent = $request->userAgent();

        return [
            // The session id is a credential. The trail keeps a fingerprint, never the id.
            'session_ref' => $request->hasSession() ? substr(hash('sha256', $request->session()->getId()), 0, 32) : null,
            'request_id' => $requestId,
            'device_id' => null,
            'ip' => $request->ip(),
            'user_agent' => $agent !== null ? mb_substr($agent, 0, 400) : null,
            'channel' => in_array($channel, self::CHANNELS, true) ? $channel : 'api',
        ];
    }

    private function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request->route() !== null ? $request : null;
    }
}
