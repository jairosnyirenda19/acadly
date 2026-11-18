<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

/** No family records anywhere: the default when no core keeps families. */
final class NoFamilyAccounts implements FamilyAccounts
{
    public function holds(string $userId): bool
    {
        return false;
    }

    public function moveAccount(string $fromUserId, string $toUserId): void {}
}
