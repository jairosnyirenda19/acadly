<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

/** Nobody signs in by phone until a part of the school binds `PhoneHolders`. */
final class NoPhoneHolders implements PhoneHolders
{
    public function nameFor(string $phone): ?string
    {
        return null;
    }
}
