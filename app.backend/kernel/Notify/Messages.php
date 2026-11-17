<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Notify\Channels\SmsProvider;
use Acadly\Kernel\Notify\Sms\SmsLength;
use Acadly\Kernel\Notify\Sms\SmsProviders;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Phone;
use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use stdClass;

/** What the Messages pages show (the approved designs): channels, notices and sent messages. */
final class Messages
{
    /** The plain words for each reason a message did not go, by its code. */
    private const array REASONS = [
        'no_provider' => 'No SMS provider is set up.',
        'no_address' => 'There is no address to send to.',
        'provider_refused' => 'The provider refused the message.',
        'provider_balance' => 'The provider account has no credit.',
        'provider_error' => 'The provider could not be reached.',
        'carrier_undeliv' => 'The phone did not take the message.',
        'carrier_expired' => 'The phone stayed off until the message expired.',
        'carrier_failed' => 'The phone network did not deliver it.',
        'carrier_rejected' => 'The phone network refused it.',
        'carrier_rejectd' => 'The phone network refused it.',
        'carrier_cancelled' => 'The message was cancelled.',
        'not_delivered' => 'The phone did not take the message.',
        'quota' => 'Over the SMS allowance for this month.',
        'quiet_hours' => 'Held for quiet hours.',
    ];

    public function __construct(
        private readonly MessageSettings $settings,
        private readonly NoticeCatalogue $catalogue,
        private readonly ModuleRegistry $modules,
        private readonly SchoolProfile $school,
        private readonly Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function channels(): array
    {
        $route = $this->settings->smsRoute();
        $monthStart = $this->clock->now()->setTimezone(self::zone())->startOfMonth()->utc();
        $sms = DB::table('notification_deliveries')->where('channel', Channel::Sms->value)->where('queued_at', '>=', $monthStart)->where('audience', '!=', 'test');
        $count = (clone $sms)->whereIn('status', [DeliveryStatus::Sent->value, DeliveryStatus::Delivered->value, DeliveryStatus::Failed->value])->count();
        $delivered = (clone $sms)->where('status', DeliveryStatus::Delivered->value)->count();
        $failed = (clone $sms)->where('status', DeliveryStatus::Failed->value)->count();
        $emails = DB::table('notification_deliveries')->where('channel', Channel::Email->value)->where('queued_at', '>=', $monthStart)->where('audience', '!=', 'test');
        $last = (clone $sms)->whereIn('status', [DeliveryStatus::Sent->value, DeliveryStatus::Delivered->value])->max('settled_at');
        $limits = $this->settings->limits();
        $email = $this->settings->emailRoute();

        return [
            'sms' => [
                'provider' => $route['provider'] ?? null,
                'providers' => array_map(fn (string $key): array => ['key' => $key, 'name' => SmsProviders::label($key) ?? $key, 'needs_username' => SmsProviders::needsUsername($key)], array_keys(SmsProviders::all())),
                'test_mode' => SmsProviders::logOnly(),
                'sender' => $route['sender'] ?? null,
                'username' => $route['username'] ?? null,
                'key_hint' => $route['hint'] ?? null,
                'connected' => $route !== null && $route['hint'] !== null,
                'last_accepted_at' => is_string($last) ? $last : null,
                'reports_address' => $route !== null ? rtrim(config()->string('app.url'), '/').'/api/v1/notify/reports/'.$route['token'] : null,
                'balance' => $route !== null ? $this->balance() : null,
            ],
            'email' => [
                'from_address' => config()->string('mail.from.address'),
                'from_name' => $email['from_name'] ?? $this->school->name(),
                'reply_to' => $email['reply_to'],
                'connected' => config()->string('mail.default') !== 'log' && config()->string('mail.default') !== 'array',
            ],
            'limits' => $limits,
            'month' => [
                'sms' => $count,
                'units' => (int) (clone $sms)->sum('units'),
                'delivered_share' => $delivered + $failed > 0 ? (int) round($delivered / ($delivered + $failed) * 100) : null,
                'emails' => (clone $emails)->whereIn('status', [DeliveryStatus::Sent->value, DeliveryStatus::Delivered->value, DeliveryStatus::Failed->value])->count(),
                'emails_accepted' => (clone $emails)->whereIn('status', [DeliveryStatus::Sent->value, DeliveryStatus::Delivered->value])->count(),
                'not_delivered' => $failed,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function notices(): array
    {
        $own = DB::table('notice_wordings')->pluck('topic_key')->all();
        $out = [];

        foreach ($this->catalogue->all() as $key => $notice) {
            $owner = $this->catalogue->ownerOf($key);
            $out[] = [
                'key' => $key,
                'name' => $notice->name(),
                'group' => $this->modules->has($owner) ? $this->modules->get($owner)->name() : 'School',
                'audience' => $notice->parties() !== [] ? 'contacts' : 'staff',
                'channels' => Notice::values($notice->allowedChannels()),
                'enabled' => $this->settings->topicEnabled($key, $notice->onAtFirst()),
                'own_words' => in_array($key, $own, true),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function notice(string $key): ?array
    {
        if (! $this->catalogue->has($key)) {
            return null;
        }

        $notice = $this->catalogue->notice($key);
        $own = $this->settings->wording($key);
        $examples = [
            ...$notice->exampleFacts(),
            'institution' => $this->school->name(),
            'school_phone' => $this->school->phone(),
            'contact' => 'Alice Banda',
            'actor' => 'Grace Phiri',
        ];
        $short = $own['short'] ?? $notice->shortText();

        return [
            'key' => $key,
            'name' => $notice->name(),
            'audience' => $notice->parties() !== [] ? 'contacts' : 'staff',
            'channels' => Notice::values($notice->allowedChannels()),
            'enabled' => $this->settings->topicEnabled($key, $notice->onAtFirst()),
            'can_switch_off' => $notice->optOutRule() !== OptOut::Never,
            'contacts' => $notice->parties() !== [] ? $this->settings->contacts($key) : null,
            'facts' => [...$notice->factNames(), ...array_values(array_diff(Notice::ALWAYS, ['actor']))],
            'examples' => $examples,
            'declared' => ['subject' => $notice->subjectText(), 'lines' => $notice->lines(), 'short' => $notice->shortText()],
            'own' => $own,
            'budget' => Notice::SHORT_BUDGET,
            'short_length' => $short !== null ? SmsLength::of(Words::fill($short, $examples)) : null,
        ];
    }

    /**
     * Every message of one day, newest first, with the totals for the band.
     *
     * @return array{day: string, today: string, totals: array{sent: int, delivered: int, waiting: int, attention: int}, messages: list<array<string, mixed>>}
     */
    public function sent(string $day, ?string $channel, ?string $state, ?string $search): array
    {
        [$from, $to] = self::dayBounds($day);
        $base = DB::table('notification_deliveries')->where('channel', '!=', 'none')->where('queued_at', '>=', $from)->where('queued_at', '<', $to);
        $all = (clone $base)->get(['status', 'reason']);
        $totals = ['sent' => 0, 'delivered' => 0, 'waiting' => 0, 'attention' => 0];

        foreach ($all as $row) {
            $s = self::state($row);
            $totals['sent'] += in_array($s, ['delivered', 'sent', 'accepted', 'not_delivered'], true) ? 1 : 0;
            $totals['delivered'] += $s === 'delivered' ? 1 : 0;
            $totals['waiting'] += $s === 'waiting' ? 1 : 0;
            $totals['attention'] += in_array($s, ['not_delivered', 'not_reachable'], true) ? 1 : 0;
        }

        $query = (clone $base)->orderByDesc('queued_at')->limit(500);

        if ($channel !== null && Channel::tryFrom($channel) !== null) {
            $query->where('channel', $channel);
        }

        if ($search !== null && trim($search) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($search))).'%';
            $digits = (string) preg_replace('/\D/', '', $search);
            $query->where(function (Builder $q) use ($like, $digits): void {
                $q->whereRaw('lower(recipient_name) like ?', [$like])->orWhereRaw('lower(subject) like ?', [$like])->orWhereRaw('lower(recipient_email) like ?', [$like]);

                if (strlen($digits) >= 4) {
                    $q->orWhere('recipient_phone', 'like', '%'.substr($digits, -9).'%');
                }
            });
        }

        $rows = [];

        foreach ($query->get() as $row) {
            $item = $this->item($row);

            if ($state === null || $item['state'] === $state) {
                $rows[] = $item;
            }
        }

        return ['day' => $day, 'today' => $this->clock->today(), 'totals' => $totals, 'messages' => $rows];
    }

    /** @return array<string, mixed>|null */
    public function message(string $id): ?array
    {
        $row = DB::table('notification_deliveries')->where('id', $id)->where('channel', '!=', 'none')->first();

        if ($row === null) {
            return null;
        }

        $item = $this->item($row);
        $again = DB::table('notification_deliveries')->where('resent_from', $id)->first(['id', 'status', 'reason', 'queued_at']);
        $channel = Row::text($row, 'channel');
        $steps = [['at' => Row::text($row, 'queued_at'), 'what' => 'written']];

        if (Row::maybeText($row, 'settled_at') !== null) {
            $steps[] = ['at' => Row::text($row, 'settled_at'), 'what' => match ($item['state']) {
                'not_reachable' => 'not_reachable',
                'over_allowance' => 'over_allowance',
                'not_delivered' => Row::maybeText($row, 'provider_ref') !== null ? 'accepted' : 'refused',
                default => 'accepted',
            }];
        }

        if (Row::maybeText($row, 'delivered_at') !== null) {
            $steps[] = ['at' => Row::text($row, 'delivered_at'), 'what' => 'delivered'];
        } elseif ($item['state'] === 'not_delivered' && Row::maybeText($row, 'provider_ref') !== null) {
            $steps[] = ['at' => Row::maybeText($row, 'settled_at') ?? Row::text($row, 'queued_at'), 'what' => 'not_delivered'];
        }

        return [
            ...$item,
            'text' => $channel === Channel::Sms->value ? (Row::maybeText($row, 'short') ?? Row::text($row, 'subject')) : Row::text($row, 'body'),
            'email_subject' => $channel === Channel::Email->value ? Row::text($row, 'subject') : null,
            'provider' => self::providerName(Row::maybeText($row, 'provider')),
            'steps' => $steps,
            'sent_again' => $again === null ? null : ['id' => Row::text($again, 'id'), 'state' => self::state($again)],
            'can_send_again' => $item['state'] === 'not_delivered' && $again === null && Row::text($row, 'audience') !== 'test',
        ];
    }

    /**
     * Every delivery of the notices whose request carries this value, newest
     * first: the messages about one record, for that record's own screen.
     *
     * @return list<array<string, mixed>>
     */
    public function carrying(string $field, string $value): array
    {
        $rows = DB::table('notification_deliveries as d')->join('notification_requests as r', 'r.id', '=', 'd.request_id')
            ->where('d.channel', '!=', 'none')->whereRaw('r.payload->>? = ?', [$field, $value])
            ->orderByDesc('d.queued_at')->limit(500)->get(['d.*']);
        $out = [];

        foreach ($rows as $row) {
            $out[] = $this->item($row);
        }

        return $out;
    }

    /**
     * The messages sent to one phone number about one record, newest first,
     * as the person who received them reads them (the parent portal).
     *
     * @return list<array{id: string, at: string, notice: string, text: string}>
     */
    public function toPhone(string $phone, string $field, string $value): array
    {
        $rows = DB::table('notification_deliveries as d')->join('notification_requests as r', 'r.id', '=', 'd.request_id')
            ->where('d.channel', Channel::Sms->value)->where('d.recipient_phone', $phone)->where('d.audience', '!=', 'test')
            ->whereRaw('r.payload->>? = ?', [$field, $value])
            ->orderByDesc('d.queued_at')->limit(100)->get(['d.id', 'd.queued_at', 'd.topic_key', 'd.short', 'd.subject']);

        return array_values(array_map(fn (stdClass $row): array => [
            'id' => Row::text($row, 'id'),
            'at' => Row::text($row, 'queued_at'),
            'notice' => $this->catalogue->has(Row::text($row, 'topic_key')) ? $this->catalogue->notice(Row::text($row, 'topic_key'))->name() : '',
            'text' => Row::maybeText($row, 'short') ?? Row::text($row, 'subject'),
        ], $rows->all()));
    }

    /** @return array<string, mixed> one delivery as the Sent messages page shows it */
    public function item(stdClass $row): array
    {
        $key = Row::text($row, 'topic_key');
        $channel = Row::text($row, 'channel');
        $state = self::state($row);
        $reason = Row::maybeText($row, 'reason');

        return [
            'id' => Row::text($row, 'id'),
            'at' => Row::text($row, 'queued_at'),
            'to' => Row::maybeText($row, 'recipient_name') ?? '',
            'address' => $channel === Channel::Sms->value ? Phone::forPeople(Row::maybeText($row, 'recipient_phone')) : Row::maybeText($row, 'recipient_email'),
            'notice' => $key === 'notify.test' ? 'Test message' : ($this->catalogue->has($key) ? $this->catalogue->notice($key)->name() : $key),
            'about' => Row::text($row, 'subject'),
            'channel' => $channel,
            'state' => $state,
            'reason' => $state === 'not_reachable'
                ? ($channel === Channel::Sms->value ? 'No phone number that can be used.' : 'No email address.')
                : ($reason !== null ? (self::REASONS[$reason] ?? 'The message was not sent.') : ($state === 'sent' ? 'No report from the phone yet.' : null)),
            'units' => Row::int($row, 'units'),
            'again' => Row::maybeText($row, 'resent_from') !== null,
        ];
    }

    /** The word the page shows for where a message stands. */
    private static function state(stdClass $row): string
    {
        $status = $row->status ?? '';
        $reason = $row->reason ?? null;
        $channel = $row->channel ?? null;

        return match ($status) {
            DeliveryStatus::Delivered->value => 'delivered',
            DeliveryStatus::Sent->value => $channel === Channel::Email->value ? 'accepted' : 'sent',
            DeliveryStatus::Failed->value => 'not_delivered',
            DeliveryStatus::Queued->value => 'waiting',
            DeliveryStatus::Suppressed->value => match ($reason) {
                'not_reachable' => 'not_reachable',
                'quota' => 'over_allowance',
                default => 'not_sent',
            },
            default => 'not_sent',
        };
    }

    private static function providerName(?string $key): ?string
    {
        return match ($key) {
            null => null,
            'mail' => 'Mail server',
            'log', 'development' => 'Test mode, not sent',
            default => SmsProviders::label($key),
        };
    }

    /**
     * The balance, read at most every ten minutes.
     *
     * @return array{value: string, at: string}|null
     */
    private function balance(): ?array
    {
        $cached = Cache::remember('notify.sms_balance', 600, fn (): array => [
            'value' => app(SmsProvider::class)->balance(),
            'at' => $this->clock->now()->toIso8601String(),
        ]);

        return $cached['value'] !== null ? ['value' => $cached['value'], 'at' => $cached['at']] : null;
    }

    /** @return array{CarbonImmutable, CarbonImmutable} the day's start and end, in UTC */
    private static function dayBounds(string $day): array
    {
        $start = CarbonImmutable::parse($day, self::zone())->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    private static function zone(): string
    {
        return config()->string('acadly.timezone', 'UTC');
    }
}
