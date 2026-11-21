<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Notifications;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\PartyResolver;
use Acadly\Kernel\Notify\Recipient;
use Acadly\Kernel\Notify\Topic;

/** The colleague asked or named to cover a lecture. */
final class TheColleague implements PartyResolver
{
    public function resolve(Topic $topic): array
    {
        if (! $topic instanceof CoverAsked) {
            return [];
        }
        $user = User::query()->find($topic->userId);

        return $user instanceof User && $user->isActive() ? [Recipient::user($user)] : [];
    }
}
