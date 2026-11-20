<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

/** The default: no student accounts. */
final class NoStudentAccounts implements StudentAccounts
{
    public function isStudent(string $userId): bool
    {
        return false;
    }

    public function accountForNumber(string $number): ?string
    {
        return null;
    }
}
