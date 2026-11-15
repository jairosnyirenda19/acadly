<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

/**
 * An SMS company (04 §8). The product is not tied to one: a deployment binds
 * the provider it uses. A development machine uses `DevelopmentSmsProvider`.
 */
interface SmsProvider
{
    /** @param  string  $to  an international number, such as +265991234567 */
    public function send(string $to, string $text): ProviderReceipt;
}
