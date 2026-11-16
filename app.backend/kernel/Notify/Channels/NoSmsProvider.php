<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

use Illuminate\Http\Request;

/** Bound when the school has not chosen an SMS provider: every text message fails, visibly. */
final class NoSmsProvider implements SmsProvider
{
    public function key(): string
    {
        return 'none';
    }

    public function send(string $to, string $text): ProviderReceipt
    {
        return ProviderReceipt::failed('no_provider');
    }

    public function report(Request $request): ?DeliveryReport
    {
        return null;
    }

    public function balance(): ?string
    {
        return null;
    }
}
