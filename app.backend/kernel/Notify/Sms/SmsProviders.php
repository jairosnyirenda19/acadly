<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Sms;

use Acadly\Kernel\Notify\Channels\NoSmsProvider;
use Acadly\Kernel\Notify\Channels\SmsProvider;

/**
 * The SMS providers a school may choose, read from `acadly.sms.providers`
 * (key => class), and the driver: `school` sends through the school's
 * choice; `log` writes each message to the log and sends nothing.
 */
final class SmsProviders
{
    public const string LOG = 'log';

    /** @return array<string, class-string<ChoosableSmsProvider>> */
    public static function all(): array
    {
        /** @var array<string, class-string<ChoosableSmsProvider>> $providers */
        $providers = config()->array('acadly.sms.providers');

        return $providers;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function label(string $key): ?string
    {
        $class = self::all()[$key] ?? null;

        return $class !== null ? $class::label() : null;
    }

    public static function needsUsername(string $key): bool
    {
        $class = self::all()[$key] ?? null;

        return $class !== null && $class::needsUsername();
    }

    /** The provider for this key and account, or one that fails every message when the key is unknown. */
    public static function connect(string $key, SmsAccount $account): SmsProvider
    {
        $class = self::all()[$key] ?? null;

        return $class !== null ? $class::connect($account) : new NoSmsProvider;
    }

    /** True when messages are written to the log and never sent. */
    public static function logOnly(): bool
    {
        return config()->string('acadly.sms.driver') === self::LOG;
    }
}
