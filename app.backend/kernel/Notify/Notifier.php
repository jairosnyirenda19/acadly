<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Jobs\ResolveNotice;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The one way to tell people something (rule 10):
 *
 *     $notifier->send(new ErrandApproved($errand->id), actor: $who);
 *
 * The call site never names recipients or channels. Inside the sender's
 * transaction it writes one request row and nothing else; recipients,
 * words and channels are resolved on the queue after commit (04 §7), so a
 * dead mail server never undoes the business action.
 */
final class Notifier
{
    public function __construct(
        private readonly NoticeCatalogue $catalogue,
        private readonly Clock $clock,
    ) {}

    /**
     * Returns the request id, or null when the same event was already sent.
     *
     * @param  list<User>  $people  named people, for a notice that allows them
     */
    public function send(Topic $topic, ?User $actor = null, array $people = []): ?string
    {
        $notice = $topic::notice();
        $key = $notice->key;

        if (! $this->catalogue->has($key) || $this->catalogue->topic($key) !== $topic::class) {
            throw new LogicException("[{$key}] is not a notice in this build.");
        }

        if ($people !== [] && ! $notice->allowsPeople()) {
            throw new LogicException("[{$key}] does not allow the sender to name people.");
        }

        $subject = $topic->subject();
        $scope = $topic->scope();
        $dedupe = $key.':'.$topic->dedupeKey();

        if (strlen($dedupe) > 255) {
            $dedupe = $key.':'.hash('sha256', $dedupe);
        }

        $write = function () use ($topic, $key, $subject, $scope, $dedupe, $actor, $people): ?string {
            $id = Ids::next();

            $inserted = DB::table('notification_requests')->insertOrIgnore([
                'id' => $id,
                'topic_key' => $key,
                'payload' => json_encode((object) $topic->toPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'subject_type' => $subject->type,
                'subject_id' => $subject->id,
                'scope_type' => $scope->type->value,
                'scope_id' => $scope->id,
                'dedupe_key' => $dedupe,
                'actor_user_id' => $actor?->id,
                'people' => json_encode(array_map(fn (User $person): string => $person->id, $people), JSON_THROW_ON_ERROR),
                'requested_at' => $this->clock->now(),
            ]);

            if ($inserted === 0) {
                return null;
            }

            DB::afterCommit(fn () => ResolveNotice::dispatch($id));

            return $id;
        };

        return DB::transactionLevel() > 0 ? $write() : DB::transaction($write);
    }
}
