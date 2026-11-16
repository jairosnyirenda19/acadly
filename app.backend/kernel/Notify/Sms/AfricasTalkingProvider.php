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
 * Africa's Talking. The account name `sandbox` uses the test service. A
 * status code of 200 or more for the recipient is a refusal, even when the
 * request itself succeeded.
 */
final class AfricasTalkingProvider implements ChoosableSmsProvider
{
    public function __construct(
        private readonly string $sender,
        private readonly string $username,
        private readonly string $key,
    ) {}

    public static function label(): string
    {
        return "Africa's Talking";
    }

    public static function needsUsername(): bool
    {
        return true;
    }

    public static function connect(SmsAccount $account): self
    {
        return new self($account->sender, $account->username, $account->key);
    }

    public function key(): string
    {
        return 'africastalking';
    }

    public function send(string $to, string $text): ProviderReceipt
    {
        $payload = ['username' => $this->username, 'to' => $to, 'message' => $text];

        if ($this->sender !== '') {
            $payload['from'] = $this->sender;
        }

        $response = $this->client()->asForm()->post($this->url('/version1/messaging'), $payload);
        $recipient = $response->json('SMSMessageData.Recipients.0');

        if ($response->failed() || ! is_array($recipient)) {
            return ProviderReceipt::failed('provider_refused');
        }

        $code = $recipient['statusCode'] ?? null;

        if (is_numeric($code) && (int) $code >= 200) {
            return ProviderReceipt::failed((int) $code === 405 ? 'provider_balance' : 'provider_refused');
        }

        $ref = $recipient['messageId'] ?? null;

        return ProviderReceipt::sent(is_string($ref) ? $ref : null, SmsLength::of($text)['units']);
    }

    public function report(Request $request): ?DeliveryReport
    {
        $ref = $request->input('id');
        $status = $request->input('status');

        if (! is_string($ref) || ! is_string($status)) {
            return null;
        }

        return match (strtolower($status)) {
            'success' => new DeliveryReport($ref, true),
            'rejected', 'failed', 'expired' => new DeliveryReport($ref, false, 'carrier_'.strtolower($status)),
            default => null,
        };
    }

    public function balance(): ?string
    {
        try {
            $response = $this->client()->get($this->url('/version1/user'), ['username' => $this->username]);
            $balance = $response->json('UserData.balance');

            return $response->successful() && is_string($balance) ? $balance : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders(['apiKey' => $this->key])->timeout(config()->integer('acadly.sms.timeout', 15))->acceptJson();
    }

    private function url(string $path): string
    {
        $base = $this->username === 'sandbox' ? 'acadly.sms.africastalking_sandbox_url' : 'acadly.sms.africastalking_url';

        return rtrim(config()->string($base), '/').$path;
    }
}
