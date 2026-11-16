<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

use Acadly\Kernel\Notify\Sms\SmsLength;
use Acadly\Kernel\Support\Ids;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Sends nothing. Keeps each message in memory and writes a log line with the
 * number masked, so the pipeline can be watched on a development machine.
 * A report of `{"ref": "...", "stat": "DELIVRD"}` settles a message, so the
 * whole path can be tried without a provider.
 */
final class DevelopmentSmsProvider implements SmsProvider
{
    /** @var list<array{to: string, text: string}> */
    public static array $sent = [];

    public function key(): string
    {
        return 'development';
    }

    public function send(string $to, string $text): ProviderReceipt
    {
        self::$sent[] = ['to' => $to, 'text' => $text];

        Log::info('A text message was accepted by the development provider.', [
            'to' => substr($to, 0, 4).str_repeat('•', max(0, strlen($to) - 7)).substr($to, -3),
            'length' => mb_strlen($text),
        ]);

        return ProviderReceipt::sent('dev-'.Ids::next(), SmsLength::of($text)['units']);
    }

    public function report(Request $request): ?DeliveryReport
    {
        $ref = $request->input('ref');
        $stat = $request->input('stat');

        if (! is_string($ref) || ! is_string($stat) || $ref === '') {
            return null;
        }

        return new DeliveryReport($ref, strtoupper($stat) === 'DELIVRD', strtoupper($stat) === 'DELIVRD' ? null : 'not_delivered');
    }

    public function balance(): ?string
    {
        return null;
    }
}
