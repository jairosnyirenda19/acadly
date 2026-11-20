<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

/**
 * Student accounts (D-101): an account a student signs in with themselves,
 * which opens only the student portal. Bound by the module that keeps
 * students; the default knows of none.
 */
interface StudentAccounts
{
    /** Whether the account is a student's own. */
    public function isStudent(string $userId): bool;

    /** The account of the student with this student number, or null. Numbers are matched without regard to case. */
    public function accountForNumber(string $number): ?string;
}
