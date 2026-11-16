<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Sms;

use Acadly\Kernel\Notify\Channels\DeliveryReport;
use Acadly\Kernel\Notify\Channels\ProviderReceipt;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * MeTicket, a Malawi gateway that reaches Airtel and TNM numbers. Its answer
 * has the same shape as Africa's Talking. A report arrives as `event: dlr`
 * with the carrier's state: DELIVRD, UNDELIV, FAILED, EXPIRED.
 */
final class MeTicketProvider implements ChoosableSmsProvider
{
    public function __construct(
        private readonly string $sender,
        private readonly string $key,
    ) {}

    public static function label(): string
    {
        return 'MeTicket';
    }

    public static function needsUsername(): bool
    {
        return false;
    }

    public static function connect(SmsAccount $account): self
    {
        return new self($account->sender, $account->key);
    }

    public function key(): string
    {
        return 'meticket';
    }

    public function send(string $to, string $text): ProviderReceipt
    {
        $response = $this->client()->post($this->url('/api/messaging/send'), ['to' => $to, 'from' => $this->sender, 'message' => $text]);

        if ($response->failed()) {
            return ProviderReceipt::failed($response->status() === 402 ? 'provider_balance' : 'provider_refused');
        }

        $recipient = $response->json('SMSMessageData.Recipients.0');

        if (! is_array($recipient)) {
            return ProviderReceipt::failed('provider_refused');
        }

        $ref = $recipient['messageId'] ?? null;

        return ProviderReceipt::sent(is_string($ref) ? $ref : null, SmsLength::of($text)['units']);
    }

    public function report(Request $request): ?DeliveryReport
    {
        $ref = $request->input('messageId');
        $stat = $request->input('stat');

        if ($request->input('event') !== 'dlr' || ! is_string($ref) || ! is_string($stat)) {
            return null;
        }

        return match (strtoupper($stat)) {
            'DELIVRD' => new DeliveryReport($ref, true),
            'UNDELIV', 'FAILED', 'EXPIRED', 'CANCELLED', 'REJECTD' => new DeliveryReport($ref, false, 'carrier_'.strtolower($stat)),
            default => null,
        };
    }

    public function balance(): ?string
    {
        try {
            $response = $this->client()->get($this->url('/api/billing/balance'));
            $available = $response->json('available') ?? $response->json('balance');

            return $response->successful() && is_scalar($available) ? 'MWK '.$available : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'ApiKey '.$this->key])->timeout(config()->integer('acadly.sms.timeout', 15))->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim(config()->string('acadly.sms.meticket_url'), '/').$path;
    }
}
