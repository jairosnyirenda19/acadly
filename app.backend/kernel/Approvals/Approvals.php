<?php

declare(strict_types=1);

namespace Acadly\Kernel\Approvals;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Approvals (the approved design): the catalogue of what needs someone
 * else's yes, the chain of steps in force for each, and who a step asks.
 *
 * A step asks the people its kind names, narrowed by three rules that never
 * bend: only someone who holds the topic's permission where it happened;
 * never the person who asked; and never someone who already said yes to an
 * earlier step. A step left with nobody goes to the chain's fallback role,
 * then to anyone who may decide, and says so.
 *
 * @phpstan-type Spec array{kind: 'anyone'|'relation'|'role'|'person', target: string|null, needs: int, when: string|null}
 * @phpstan-type Chain array{steps: list<Spec>, fallback_role_id: string|null, changed: bool, saved_by: string|null, saved_at: string|null}
 */
final class Approvals
{
    public const array KINDS = ['anyone', 'relation', 'role', 'person'];

    public function __construct(
        private readonly Access $access,
        private readonly ModuleActivation $modules,
        private readonly Clock $clock,
    ) {}

    /** @return list<ApprovalTopic> the topics of modules that are on, in catalogue order */
    public function topics(): array
    {
        $topics = [];

        foreach (app()->tagged(ApprovalTopic::TAG) as $topic) {
            if ($topic instanceof ApprovalTopic && $this->modules->isActive($topic->module())) {
                $topics[] = $topic;
            }
        }

        return $topics;
    }

    public function topic(string $key): ?ApprovalTopic
    {
        foreach ($this->topics() as $topic) {
            if ($topic->key() === $key) {
                return $topic;
            }
        }

        return null;
    }

    /** @return Spec */
    public static function anyone(): array
    {
        return ['kind' => 'anyone', 'target' => null, 'needs' => 1, 'when' => null];
    }

    /** @return Chain the chain in force: the newest saved, or the default of anyone who may decide */
    public function chain(ApprovalTopic $topic): array
    {
        $row = DB::table('approval_chains')->where('topic_key', $topic->key())->orderByDesc('seq')->first();
        $steps = $row !== null ? json_decode(Row::maybeText($row, 'steps') ?? 'null', true) : null;

        if (! is_array($steps) || $steps === []) {
            return ['steps' => [self::anyone()], 'fallback_role_id' => null, 'changed' => false,
                'saved_by' => $row !== null ? Row::maybeText($row, 'saved_by') : null, 'saved_at' => $row !== null ? Row::maybeText($row, 'created_at') : null];
        }

        return ['steps' => array_values(array_map(self::spec(...), array_filter($steps, 'is_array'))), 'fallback_role_id' => Row::maybeText($row, 'fallback_role_id'),
            'changed' => true, 'saved_by' => Row::maybeText($row, 'saved_by'), 'saved_at' => Row::maybeText($row, 'created_at')];
    }

    /**
     * @param  array<array-key, mixed>  $s
     * @return Spec
     */
    public static function spec(array $s): array
    {
        $kind = in_array($s['kind'] ?? null, self::KINDS, true) ? $s['kind'] : 'anyone';
        $needs = is_int($s['needs'] ?? null) ? max(1, min(5, $s['needs'])) : 1;

        return ['kind' => $kind, 'target' => is_string($s['target'] ?? null) ? $s['target'] : null, 'needs' => $needs, 'when' => is_string($s['when'] ?? null) ? $s['when'] : null];
    }

    /**
     * The words for a step: "The class teacher", "Head teacher", "Martha Kalua".
     *
     * @param  Spec  $spec
     */
    public function label(ApprovalTopic $topic, array $spec): string
    {
        $target = $spec['target'] ?? '';
        $name = match ($spec['kind']) {
            'relation' => $topic->relations()[$target] ?? null,
            'role' => Role::query()->whereKey($target)->value('name'),
            'person' => User::query()->whereKey($target)->value('name'),
            default => 'Anyone who may decide',
        };

        return is_string($name) ? $name : match ($spec['kind']) {
            'role' => 'A role no longer in use',
            'person' => 'A person no longer here',
            default => 'Someone named by the request',
        };
    }

    /**
     * Who a step asks, and a sentence when it had to look elsewhere.
     *
     * @param  Spec  $spec
     * @param  list<string>  $already  people who said yes to an earlier step
     * @return array{asked: list<string>, note: string|null}
     */
    public function resolve(ApprovalTopic $topic, ApprovalCase $case, array $spec, ?string $fallbackRoleId, array $already): array
    {
        $named = match ($spec['kind']) {
            'relation' => $case->related[$spec['target'] ?? ''] ?? [],
            'role' => $this->roleHolders((string) $spec['target'], $case->at),
            'person' => $spec['target'] !== null ? [$spec['target']] : [],
            default => $this->pool($topic, $case->at),
        };
        $fit = $this->fit($topic, $case, $named, $already);

        if ($fit !== []) {
            return ['asked' => $fit, 'note' => null];
        }

        $why = in_array($case->requesterId, $named, true) && count($named) === 1
            ? 'The person who asked would have decided this step, and nobody approves their own request.'
            : 'Nobody can decide this step today.';

        if ($fallbackRoleId !== null) {
            $fit = $this->fit($topic, $case, $this->roleHolders($fallbackRoleId, $case->at), $already);
            $role = Role::query()->whereKey($fallbackRoleId)->value('name');

            if ($fit !== []) {
                return ['asked' => $fit, 'note' => $why.' It went to '.(is_string($role) ? $role : 'the fallback').'.'];
            }
        }

        $fit = $this->fit($topic, $case, $this->pool($topic, $case->at), $already);

        return ['asked' => $fit, 'note' => $fit !== [] ? $why.' It went to anyone who may decide.' : 'Nobody who may decide this can be asked today.'];
    }

    /** @return list<array{id: string, name: string}> roles in force, for choosing */
    public function roles(): array
    {
        return array_values(Role::query()->inForce()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Role $r): array => ['id' => $r->id, 'name' => $r->name])->all());
    }

    /**
     * People who hold the topic's permission somewhere, for naming one person.
     *
     * @return list<array{id: string, name: string}>
     */
    public function people(ApprovalTopic $topic): array
    {
        $ids = DB::table('access_role_assignments as a')->join('access_role_permissions as p', 'p.role_id', '=', 'a.role_id')
            ->where('p.permission_key', $topic->permission()->key())->where('a.starts_at', '<=', $this->clock->now())
            ->where(fn (Builder $q): Builder => $q->whereNull('a.ends_at')->orWhere('a.ends_at', '>', $this->clock->now()))
            ->distinct()->pluck('a.user_id')->all();

        return array_values(User::query()->whereIn('id', $ids)->where('status', AccountStatus::Active->value)->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all());
    }

    /**
     * @param  list<string>  $ids
     * @param  list<string>  $already
     * @return list<string>
     */
    private function fit(ApprovalTopic $topic, ApprovalCase $case, array $ids, array $already): array
    {
        $ids = array_values(array_diff(array_unique($ids), [$case->requesterId], $already));

        if ($ids === []) {
            return [];
        }

        $users = User::query()->whereIn('id', $ids)->where('status', AccountStatus::Active->value)->get();

        return array_values($users->filter(fn (User $u): bool => $this->access->allows($u, $topic->permission(), $case->at))
            ->sortBy('name')->map(fn (User $u): string => $u->id)->all());
    }

    /** @return list<string> */
    private function pool(ApprovalTopic $topic, ScopeRef $at): array
    {
        return $this->access->holderIds($topic->permission(), $at);
    }

    /** @return list<string> holders of the role where it happened, or anywhere above it */
    private function roleHolders(string $roleId, ScopeRef $at): array
    {
        $places = [$at, ...$this->access->ancestors($at)];
        $now = $this->clock->now();

        $ids = DB::table('access_role_assignments')->where('role_id', $roleId)->where('starts_at', '<=', $now)
            ->where(fn (Builder $q): Builder => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->where(function (Builder $q) use ($places): void {
                foreach ($places as $place) {
                    $q->orWhere(fn (Builder $one): Builder => $one->where('scope_type', $place->type->value)
                        ->where(fn (Builder $id): Builder => $place->id === null ? $id->whereNull('scope_id') : $id->where('scope_id', $place->id)));
                }
            })->distinct()->pluck('user_id')->all();

        return array_values(array_filter($ids, is_string(...)));
    }
}
