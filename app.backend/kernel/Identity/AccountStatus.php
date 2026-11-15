<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

enum AccountStatus: string
{
    /** May sign in. */
    case Active = 'active';

    /** May not sign in. The account and everything it did are kept. */
    case Disabled = 'disabled';

    /** Made by the school and invited. May sign in once the person sets a password with the invitation link. */
    case Invited = 'invited';
}
