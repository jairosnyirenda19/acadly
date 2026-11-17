<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

/**
 * Who the school knows by a phone number, for sign-in by code (D-082).
 *
 * The kernel names no module: the part of the school that keeps phone
 * numbers binds this. Knowing a number lets its holder sign in; it gives
 * no access to anything. Access to a learner comes only from a confirmed
 * link (03 §4).
 */
interface PhoneHolders
{
    /**
     * The name the school has for this number, or null when the number is
     * not one the school keeps.
     *
     * @param  string  $phone  the international form, such as +265991234567
     */
    public function nameFor(string $phone): ?string;
}
