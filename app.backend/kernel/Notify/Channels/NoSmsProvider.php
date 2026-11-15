<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

/** Bound when a deployment has no SMS provider: every text message fails, visibly. */
final class NoSmsProvider implements SmsProvider
{
    public function send(string $to, string $text): ProviderReceipt
    {
        return ProviderReceipt::failed('no_provider');
    }
}
