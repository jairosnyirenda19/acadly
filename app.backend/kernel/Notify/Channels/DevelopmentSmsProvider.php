<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\Log;

/**
 * Sends nothing. Keeps each message in memory and writes a log line with the
 * number masked, so the pipeline can be watched on a development machine.
 */
final class DevelopmentSmsProvider implements SmsProvider
{
    /** @var list<array{to: string, text: string}> */
    public static array $sent = [];

    public function send(string $to, string $text): ProviderReceipt
    {
        self::$sent[] = ['to' => $to, 'text' => $text];

        Log::info('A text message was accepted by the development provider.', [
            'to' => substr($to, 0, 4).str_repeat('•', max(0, strlen($to) - 7)).substr($to, -3),
            'length' => mb_strlen($text),
        ]);

        return ProviderReceipt::sent('dev-'.Ids::next(), max(1, (int) ceil(mb_strlen($text) / 160)));
    }
}
