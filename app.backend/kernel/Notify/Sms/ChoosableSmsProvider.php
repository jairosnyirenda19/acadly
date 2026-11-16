<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Sms;

use Acadly\Kernel\Notify\Channels\SmsProvider;

/**
 * An SMS company a school may choose on the Messages page. To add one, write
 * a class that implements this contract and list it under
 * `acadly.sms.providers` with its key. Nothing else names a provider.
 */
interface ChoosableSmsProvider extends SmsProvider
{
    /** The name the school sees: "Africa's Talking". */
    public static function label(): string;

    /** True when the provider needs an account name as well as the key. */
    public static function needsUsername(): bool;

    /** The provider, ready to send with the school's account. */
    public static function connect(SmsAccount $account): self;
}
