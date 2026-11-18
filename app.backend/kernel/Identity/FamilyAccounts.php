<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

/**
 * The family records an account holds in the parent portal (D-087), so a
 * member of staff who is also a parent keeps one account. Bound by the core
 * that keeps families; the default holds nothing.
 */
interface FamilyAccounts
{
    /** The account holds family records: a parent role, or links to children. */
    public function holds(string $userId): bool;

    /** Every family record one account holds now belongs to another. */
    public function moveAccount(string $fromUserId, string $toUserId): void;
}
