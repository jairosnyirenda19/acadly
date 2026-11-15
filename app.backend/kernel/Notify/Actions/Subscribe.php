<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\NoticeCatalogue;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A member of staff asks to hear a topic at a scope (04 §4, the desk).
 *
 * A topic that someone acts on is open only to a person who holds one of
 * its acting permissions at that scope: the words may name records the
 * person could not otherwise see. A topic with no acting permission is
 * open to any active account.
 */
final class Subscribe
{
    public function __construct(
        private readonly NoticeCatalogue $catalogue,
        private readonly PermissionCatalogue $permissions,
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @param  list<Channel>|null  $channels  null: the notice's default channels */
    public function handle(User $who, string $topicKey, ScopeRef $at, ?array $channels = null): string
    {
        if (! $this->catalogue->has($topicKey) || ! $this->catalogue->notice($topicKey)->hasDesk()) {
            throw ValidationException::withMessages(['topic' => ['This notice cannot be subscribed to.']]);
        }

        $notice = $this->catalogue->notice($topicKey);
        $acting = $notice->actingPermissions();

        if ($acting !== [] && ! array_filter($acting, fn (string $key): bool => $this->access->allows($who, $this->permissions->get($key), $at))) {
            throw ValidationException::withMessages(['topic' => ['This notice is for staff who can act on it at this scope.']]);
        }

        foreach ($channels ?? [] as $channel) {
            if (! in_array($channel, $notice->allowedChannels(), true)) {
                throw ValidationException::withMessages(['channels' => ['The notice cannot be sent on this channel.']]);
            }
        }

        return DB::transaction(function () use ($who, $topicKey, $at, $channels): string {
            $active = DB::table('notification_subscriptions')
                ->where('user_id', $who->id)->where('topic_key', $topicKey)
                ->where('scope_type', $at->type->value)->where('scope_id', $at->id)
                ->whereNull('ended_at')->exists();

            if ($active) {
                throw ValidationException::withMessages(['topic' => ['The subscription already exists.']]);
            }

            $id = Ids::next();

            DB::table('notification_subscriptions')->insert([
                'id' => $id,
                'user_id' => $who->id,
                'topic_key' => $topicKey,
                'scope_type' => $at->type->value,
                'scope_id' => $at->id,
                'channels' => $channels !== null ? json_encode(array_map(fn (Channel $c): string => $c->value, $channels), JSON_THROW_ON_ERROR) : null,
                'source' => 'chosen',
                'started_at' => $this->clock->now(),
            ]);

            $this->audit->record(KernelAudit::Subscribed, actor: $this->audit->actorFor($who), at: $at, meta: ['topic' => $topicKey]);

            return $id;
        });
    }
}
