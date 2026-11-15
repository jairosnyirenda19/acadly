<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Actions;

use Acadly\Kernel\Audit\NotAudited;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Illuminate\Support\Facades\DB;

/** Marks one of the person's own in-app notices as read. Only their own. */
#[NotAudited('A person reading their own notices changes no record of the school.')]
final class MarkNotificationRead
{
    public function __construct(private readonly Clock $clock) {}

    /** Returns false when the notice is not theirs. */
    public function handle(User $who, string $notificationId): bool
    {
        $mine = DB::table('in_app_notifications')->where('id', $notificationId)->where('user_id', $who->id);

        if (! $mine->exists()) {
            return false;
        }

        $mine->whereNull('read_at')->update(['read_at' => $this->clock->now()]);

        return true;
    }
}
