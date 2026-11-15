<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Access\Events\RoleAssigned;
use Acadly\Kernel\Events\DomainEvent;
use Acadly\Kernel\Events\EventConsumer;
use Acadly\Kernel\Events\EventRecord;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * When a person gains a role, subscribe them to the desk topics they can act
 * on (`actedOnWith` ∩ the role's permissions), at the role's scope (04 §4).
 * Seeded once: a subscription that ever existed for that person, topic and
 * scope is never added again, so one they removed stays removed.
 */
final class SeedDeskSubscriptions implements EventConsumer
{
    public function __construct(
        private readonly NoticeCatalogue $catalogue,
        private readonly Clock $clock,
    ) {}

    public static function consumerKey(): string
    {
        return 'notify.seed_desk';
    }

    public function handle(DomainEvent $event, EventRecord $record): void
    {
        if (! $event instanceof RoleAssigned) {
            return;
        }

        $held = DB::table('access_role_permissions as rp')
            ->join('access_roles as r', 'r.id', '=', 'rp.role_id')
            ->where('r.key', $event->roleKey)
            ->pluck('rp.permission_key')
            ->filter(fn (mixed $key): bool => is_string($key))
            ->all();

        foreach ($this->catalogue->all() as $key => $notice) {
            if (! $notice->hasDesk() || array_intersect($notice->actingPermissions(), $held) === []) {
                continue;
            }

            $ever = DB::table('notification_subscriptions')
                ->where('user_id', $event->userId)->where('topic_key', $key)
                ->where('scope_type', $event->scopeType)->where('scope_id', $event->scopeId)
                ->exists();

            if ($ever) {
                continue;
            }

            DB::table('notification_subscriptions')->insert([
                'id' => Ids::next(),
                'user_id' => $event->userId,
                'topic_key' => $key,
                'scope_type' => $event->scopeType,
                'scope_id' => $event->scopeId,
                'channels' => null,
                'source' => 'seeded',
                'started_at' => $this->clock->now(),
            ]);
        }
    }
}
