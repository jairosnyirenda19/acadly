<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\DeliveryStatus;
use Acadly\Kernel\Notify\MessageSettings;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\NoticeCatalogue;
use Acadly\Kernel\Notify\NoticeDispatcher;
use Acadly\Kernel\Notify\NotifyPermission;
use Acadly\Kernel\Notify\OptOut;
use Acadly\Kernel\Notify\Words;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Phone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Messages page (the approved design): the SMS provider and its key, the
 * email name and reply address, quiet hours and the SMS allowance, the words
 * of each notice, a test message, and a message sent again. Each change is
 * an audit line. An access key is kept encrypted and never written to the
 * audit trail.
 */
final class MessageActions
{
    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly NoticeCatalogue $catalogue,
        private readonly NoticeDispatcher $dispatcher,
        private readonly MessageSettings $settings,
        private readonly Clock $clock,
    ) {}

    public function saveSms(User $by, string $provider, ?string $sender, ?string $username, ?string $key): void
    {
        $this->access->check($by, NotifyPermission::Manage);
        $sender = trim((string) $sender);
        $username = trim((string) $username);
        $key = trim((string) $key);
        $current = $this->settings->smsRoute();
        $errors = [];

        if (! array_key_exists($provider, MessageSettings::PROVIDERS)) {
            $errors['provider'] = ['Choose a provider.'];
        }

        if ($sender === '' || preg_match('/^[A-Za-z0-9 ]{1,11}$/', $sender) !== 1) {
            $errors['sender'] = ['Up to 11 letters and digits, as the provider approved it.'];
        }

        if ($provider === 'africastalking' && $username === '') {
            $errors['username'] = ["Enter the account name from Africa's Talking."];
        }

        if ($key === '' && ($current === null || $current['provider'] !== $provider || $current['hint'] === null)) {
            $errors['key'] = ['Enter the access key from the provider.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($by, $provider, $sender, $username, $key, $current): void {
            $row = ['provider' => $provider, 'sender' => $sender, 'username' => $username !== '' ? $username : null, 'updated_by' => $by->id, 'updated_at' => $this->clock->now()];

            if ($key !== '') {
                $row['secret'] = Crypt::encryptString($key);
                $row['secret_hint'] = mb_substr($key, -4);
            }

            DB::table('notification_routes')->updateOrInsert(['channel' => Channel::Sms->value], $current === null ? [...$row, 'receipt_token' => Str::random(48)] : $row);

            $this->audit->record(KernelAudit::ChannelSaved, actor: $this->audit->actorFor($by),
                meta: ['channel' => Channel::Sms->value, 'provider' => $provider, 'sender' => $sender, 'key_replaced' => $key !== '']);
        });

        Cache::forget('notify.sms_balance');
    }

    public function saveEmail(User $by, string $fromName, ?string $replyTo): void
    {
        $this->access->check($by, NotifyPermission::Manage);
        $fromName = trim($fromName);
        $replyTo = mb_strtolower(trim((string) $replyTo));
        $errors = [];

        if ($fromName === '' || mb_strlen($fromName) > 120) {
            $errors['from_name'] = ['Enter the name families see, such as the name of the school.'];
        }

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            $errors['reply_to'] = ['Enter a valid email address, or leave it empty.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($by, $fromName, $replyTo): void {
            $exists = DB::table('notification_routes')->where('channel', Channel::Email->value)->exists();
            $row = ['provider' => 'mail', 'from_name' => $fromName, 'reply_to' => $replyTo !== '' ? $replyTo : null, 'updated_by' => $by->id, 'updated_at' => $this->clock->now()];

            DB::table('notification_routes')->updateOrInsert(['channel' => Channel::Email->value], $exists ? $row : [...$row, 'receipt_token' => Str::random(48)]);

            $this->audit->record(KernelAudit::ChannelSaved, actor: $this->audit->actorFor($by),
                meta: ['channel' => Channel::Email->value, 'from_name' => $fromName, 'reply_to' => $replyTo]);
        });
    }

    public function saveLimits(User $by, ?string $quietFrom, ?string $quietTo, ?int $smsPerMonth): void
    {
        $this->access->check($by, NotifyPermission::Manage);
        $errors = [];

        foreach (['quiet_from' => $quietFrom, 'quiet_to' => $quietTo] as $field => $value) {
            if ($value !== null && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value) !== 1) {
                $errors[$field] = ['Enter a time such as 19:00.'];
            }
        }

        if (($quietFrom === null) !== ($quietTo === null)) {
            $errors['quiet_to'] = ['Give both times, or neither.'];
        }

        if ($smsPerMonth !== null && ($smsPerMonth < 1 || $smsPerMonth > 1_000_000)) {
            $errors['sms_per_month'] = ['Enter a number of SMS, or leave it empty for no limit.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($by, $quietFrom, $quietTo, $smsPerMonth): void {
            DB::table('notification_limits')->updateOrInsert(['id' => 'school'], [
                'quiet_from' => $quietFrom, 'quiet_to' => $quietTo, 'sms_per_month' => $smsPerMonth, 'updated_by' => $by->id, 'updated_at' => $this->clock->now(),
            ]);

            $this->audit->record(KernelAudit::LimitsSaved, actor: $this->audit->actorFor($by),
                meta: ['quiet_from' => $quietFrom, 'quiet_to' => $quietTo, 'sms_per_month' => $smsPerMonth]);
        });
    }

    /**
     * The words, whether the notice is sent, and which contacts hear it. A
     * part equal to the declared words is not stored, so an improved
     * declaration still reaches this school.
     *
     * @param  list<string>|null  $lines
     */
    public function saveNotice(User $by, string $key, ?string $subject, ?array $lines, ?string $short, bool $enabled, ?string $contacts): void
    {
        $this->access->check($by, NotifyPermission::Manage);

        if (! $this->catalogue->has($key)) {
            throw ValidationException::withMessages(['notice' => ['This notice is not part of this build.']]);
        }

        $notice = $this->catalogue->notice($key);
        $subject = self::clean($subject);
        $short = self::clean($short);
        $lines = $lines === null ? null : array_values(array_filter(array_map(fn (string $l): string => trim($l), $lines), fn (string $l): bool => $l !== ''));
        $errors = $this->checkWords($notice, $subject, $lines, $short);

        if (! $enabled && $notice->optOutRule() === OptOut::Never) {
            $errors['enabled'] = ['This notice is always sent.'];
        }

        if ($contacts !== null && ! in_array($contacts, ['main', 'every'], true)) {
            $errors['contacts'] = ['Choose who is told.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $own = [
            'subject' => $subject !== null && $subject !== $notice->subjectText() ? $subject : null,
            'lines' => $lines !== null && $lines !== [] && $lines !== $notice->lines() ? implode("\n", $lines) : null,
            'short' => $short !== null && $short !== $notice->shortText() ? $short : null,
        ];

        DB::transaction(function () use ($by, $key, $own, $enabled, $contacts): void {
            if ($own['subject'] === null && $own['lines'] === null && $own['short'] === null) {
                DB::table('notice_wordings')->where('topic_key', $key)->where('locale', MessageSettings::LOCALE)->delete();
            } else {
                DB::table('notice_wordings')->updateOrInsert(['topic_key' => $key, 'locale' => MessageSettings::LOCALE],
                    [...$own, 'updated_by' => $by->id, 'updated_at' => $this->clock->now()]);
            }

            DB::table('institution_topic_settings')->updateOrInsert(['topic_key' => $key],
                ['enabled' => $enabled, 'contacts' => $contacts, 'updated_by' => $by->id, 'updated_at' => $this->clock->now()]);

            $this->audit->record(KernelAudit::NoticeSaved, actor: $this->audit->actorFor($by),
                meta: ['topic' => $key, 'enabled' => $enabled, 'contacts' => $contacts, 'own_words' => implode(',', array_keys(array_filter($own, fn (?string $v): bool => $v !== null)))]);
        });
    }

    public function resetWording(User $by, string $key): void
    {
        $this->access->check($by, NotifyPermission::Manage);

        DB::transaction(function () use ($by, $key): void {
            DB::table('notice_wordings')->where('topic_key', $key)->delete();
            $this->audit->record(KernelAudit::WordingReset, actor: $this->audit->actorFor($by), meta: ['topic' => $key]);
        });
    }

    public function test(User $by, Channel $channel, string $address): string
    {
        $this->access->check($by, NotifyPermission::Manage);

        $to = match ($channel) {
            Channel::Sms => Phone::international($address),
            Channel::Email => filter_var(trim($address), FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower(trim($address)) : null,
            Channel::InApp => null,
        };

        if ($to === null) {
            throw ValidationException::withMessages(['address' => [$channel === Channel::Sms ? 'Enter a phone number, such as 0991 234 567.' : 'Enter a valid email address.']]);
        }

        $words = $this->settingsName().': this is a test message. Messages are reaching this '.($channel === Channel::Sms ? 'phone.' : 'address.');

        return DB::transaction(function () use ($by, $channel, $to, $words): string {
            $id = $this->dispatcher->test($channel, $to, $words, $by);
            $this->audit->record(KernelAudit::TestSent, actor: $this->audit->actorFor($by), meta: ['channel' => $channel->value, 'delivery' => $id]);

            return $id;
        });
    }

    public function again(User $by, string $deliveryId): string
    {
        $this->access->check($by, NotifyPermission::Manage);

        return DB::transaction(function () use ($by, $deliveryId): string {
            $row = DB::table('notification_deliveries')->where('id', $deliveryId)->lockForUpdate()->first(['status', 'audience']);

            if ($row === null || $row->status !== DeliveryStatus::Failed->value || $row->audience === 'test') {
                throw ValidationException::withMessages(['delivery' => ['Only a message that was not delivered can be sent again.']]);
            }

            $id = $this->dispatcher->again($deliveryId, $by) ?? throw ValidationException::withMessages(['delivery' => ['This message was already sent again.']]);
            $this->audit->record(KernelAudit::SentAgain, actor: $this->audit->actorFor($by), meta: ['delivery' => $deliveryId, 'again' => $id]);

            return $id;
        });
    }

    /**
     * @param  list<string>|null  $lines
     * @return array<string, list<string>>
     */
    private function checkWords(Notice $notice, ?string $subject, ?array $lines, ?string $short): array
    {
        $known = [...$notice->factNames(), ...Notice::ALWAYS];
        $errors = [];

        foreach (['subject' => $subject === null ? [] : [$subject], 'lines' => $lines ?? [], 'short' => $short === null ? [] : [$short]] as $field => $texts) {
            foreach ($texts as $text) {
                $unknown = array_values(array_diff(Words::tokensIn($text), $known));

                if ($unknown !== []) {
                    $errors[$field] = ['This notice has no fact called :'.$unknown[0].'.'];
                }
            }
        }

        if ($lines !== null && $lines === []) {
            $errors['lines'] = ['Write at least one line.'];
        }

        if ($short !== null && mb_strlen($short) > Notice::SHORT_BUDGET) {
            $errors['short'] = ['Keep the SMS words to '.Notice::SHORT_BUDGET.' characters before the facts are filled.'];
        }

        if (in_array(Channel::Sms, $notice->allowedChannels(), true) && $short === null && $notice->shortText() === null) {
            $errors['short'] = ['Write the SMS words.'];
        }

        return $errors;
    }

    private function settingsName(): string
    {
        return app(SchoolProfile::class)->shortName();
    }

    private static function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim((string) preg_replace('/[ \t]+/u', ' ', $text));

        return $text === '' ? null : $text;
    }
}
