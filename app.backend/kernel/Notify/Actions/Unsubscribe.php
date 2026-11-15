<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Actions;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ends a desk subscription. The row is kept, so a seeded subscription the
 * person removed is never added back when they gain another role (04 §4).
 */
final class Unsubscribe
{
    public function __construct(
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function handle(User $who, string $topicKey, ScopeRef $at): void
    {
        DB::transaction(function () use ($who, $topicKey, $at): void {
            $ended = DB::table('notification_subscriptions')
                ->where('user_id', $who->id)->where('topic_key', $topicKey)
                ->where('scope_type', $at->type->value)->where('scope_id', $at->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => $this->clock->now(), 'ended_by' => $who->id]);

            if ($ended === 0) {
                throw ValidationException::withMessages(['topic' => ['There is no such subscription.']]);
            }

            $this->audit->record(KernelAudit::Unsubscribed, actor: $this->audit->actorFor($who), at: $at, meta: ['topic' => $topicKey]);
        });
    }
}
