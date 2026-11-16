<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Notify\Channels\DevelopmentSmsProvider;
use Acadly\Kernel\Notify\Channels\NoSmsProvider;
use Acadly\Kernel\Notify\Channels\SmsProvider;
use Acadly\Kernel\Notify\Sms\AfricasTalkingProvider;
use Acadly\Kernel\Notify\Sms\MeTicketProvider;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * What the school chose for its messages (04 §5, §8): the SMS provider and
 * its key, the email name and reply address, quiet hours, its own SMS
 * allowance, its own wording, and the notices it switched off. Read fresh
 * each time, so a change on the Messages page applies to the next message.
 */
final class MessageSettings
{
    /** The SMS providers a school may choose, by key. */
    public const array PROVIDERS = ['meticket' => 'MeTicket', 'africastalking' => "Africa's Talking"];

    public const string LOCALE = 'en';

    public function __construct(private readonly Clock $clock) {}

    /** @return array{provider: string, sender: string|null, username: string|null, hint: string|null, token: string, updated_at: string}|null */
    public function smsRoute(): ?array
    {
        $row = DB::table('notification_routes')->where('channel', Channel::Sms->value)->first();

        return $row === null ? null : [
            'provider' => Row::text($row, 'provider'),
            'sender' => Row::maybeText($row, 'sender'),
            'username' => Row::maybeText($row, 'username'),
            'hint' => Row::maybeText($row, 'secret_hint'),
            'token' => Row::text($row, 'receipt_token'),
            'updated_at' => Row::text($row, 'updated_at'),
        ];
    }

    /** @return array{from_name: string|null, reply_to: string|null} */
    public function emailRoute(): array
    {
        $row = DB::table('notification_routes')->where('channel', Channel::Email->value)->first();

        return ['from_name' => $row !== null ? Row::maybeText($row, 'from_name') : null, 'reply_to' => $row !== null ? Row::maybeText($row, 'reply_to') : null];
    }

    /**
     * The provider that sends now. Without a choice, a development machine
     * keeps messages in memory and any other machine fails them, visibly.
     */
    public function smsProvider(): SmsProvider
    {
        $row = DB::table('notification_routes')->where('channel', Channel::Sms->value)->first();

        if ($row === null) {
            return app()->environment(['local', 'testing']) ? new DevelopmentSmsProvider : new NoSmsProvider;
        }

        $secret = $this->secret(Row::maybeText($row, 'secret'));
        $sender = Row::maybeText($row, 'sender') ?? '';

        return match (Row::text($row, 'provider')) {
            'meticket' => $secret !== null ? new MeTicketProvider($sender, $secret) : new NoSmsProvider,
            'africastalking' => $secret !== null ? new AfricasTalkingProvider($sender, Row::maybeText($row, 'username') ?? '', $secret) : new NoSmsProvider,
            'development' => new DevelopmentSmsProvider,
            default => new NoSmsProvider,
        };
    }

    /** The provider whose reports may arrive at this address, or null for an address nobody was given. */
    public function providerForReceipts(string $token): ?SmsProvider
    {
        $match = DB::table('notification_routes')->where('channel', Channel::Sms->value)->value('receipt_token');

        return is_string($match) && hash_equals($match, $token) ? $this->smsProvider() : null;
    }

    /** @return array{quiet_from: string|null, quiet_to: string|null, sms_per_month: int|null} */
    public function limits(): array
    {
        $row = DB::table('notification_limits')->where('id', 'school')->first();

        if ($row === null) {
            return ['quiet_from' => null, 'quiet_to' => null, 'sms_per_month' => null];
        }

        $cap = $row->sms_per_month ?? null;

        return [
            'quiet_from' => self::hm(Row::maybeText($row, 'quiet_from')),
            'quiet_to' => self::hm(Row::maybeText($row, 'quiet_to')),
            'sms_per_month' => is_numeric($cap) ? (int) $cap : null,
        ];
    }

    /** When quiet hours end, if the moment falls inside them; otherwise null. Hours are the school's own. */
    public function quietUntil(CarbonImmutable $moment): ?CarbonImmutable
    {
        $limits = $this->limits();

        if ($limits['quiet_from'] === null || $limits['quiet_to'] === null || $limits['quiet_from'] === $limits['quiet_to']) {
            return null;
        }

        $local = $moment->setTimezone(config()->string('acadly.timezone', 'UTC'));
        $now = $local->format('H:i');
        [$from, $to] = [$limits['quiet_from'], $limits['quiet_to']];
        $inside = $from < $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);

        if (! $inside) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $to));
        $end = $local->setTime($hour, $minute);

        return ($end <= $local ? $end->addDay() : $end)->utc();
    }

    /**
     * The school's words for a notice, part by part; null keeps the declared part.
     *
     * @return array{subject: string|null, lines: list<string>|null, short: string|null}|null
     */
    public function wording(string $topicKey): ?array
    {
        $row = DB::table('notice_wordings')->where('topic_key', $topicKey)->where('locale', self::LOCALE)->first();

        if ($row === null) {
            return null;
        }

        $lines = Row::maybeText($row, 'lines');

        return [
            'subject' => Row::maybeText($row, 'subject'),
            'lines' => $lines !== null ? array_values(array_filter(explode("\n", $lines), fn (string $l): bool => trim($l) !== '')) : null,
            'short' => Row::maybeText($row, 'short'),
        ];
    }

    public function topicEnabled(string $topicKey): bool
    {
        $enabled = DB::table('institution_topic_settings')->where('topic_key', $topicKey)->value('enabled');

        return $enabled === null || (bool) $enabled;
    }

    /** Which contacts of a party hear the notice: the main contact only (the default), or every contact. */
    public function contacts(string $topicKey): string
    {
        $value = DB::table('institution_topic_settings')->where('topic_key', $topicKey)->value('contacts');

        return $value === 'every' ? 'every' : 'main';
    }

    private function secret(?string $sealed): ?string
    {
        if ($sealed === null) {
            return null;
        }

        try {
            $plain = Crypt::decryptString($sealed);

            return $plain !== '' ? $plain : null;
        } catch (DecryptException) {
            return null;
        }
    }

    private static function hm(?string $time): ?string
    {
        return $time !== null ? substr($time, 0, 5) : null;
    }

    public function now(): CarbonImmutable
    {
        return $this->clock->now();
    }
}
