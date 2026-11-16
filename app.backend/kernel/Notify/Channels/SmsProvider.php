<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

use Illuminate\Http\Request;

/**
 * Sends text messages (04 §8). The product is not tied to one company: each
 * company a school may choose implements `ChoosableSmsProvider` and is
 * listed in `acadly.sms.providers`. The `log` driver sends nothing.
 */
interface SmsProvider
{
    /** The provider's key, kept on each delivery: 'meticket'. */
    public function key(): string;

    /** @param  string  $to  an international number, such as +265991234567 */
    public function send(string $to, string $text): ProviderReceipt;

    /** A delivery report the provider posted, or null when the request is not one. */
    public function report(Request $request): ?DeliveryReport;

    /** The account balance as the provider states it, such as "MWK 412600.00", or null when it cannot be read. */
    public function balance(): ?string;
}
