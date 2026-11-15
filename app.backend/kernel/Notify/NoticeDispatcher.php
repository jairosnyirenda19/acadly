<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Access\ScopeHierarchy;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\Licence\Licences;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Notify\Channels\ChannelAdapters;
use Acadly\Kernel\Notify\Channels\RenderedNotice;
use Acadly\Kernel\Notify\Jobs\DeliverNotice;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

/**
 * Turns a sent notice into deliveries, and sends each one (04 §7).
 *
 * Resolve, once per request:
 *  1. the notice and its module: a module that is off sends nothing;
 *  2. the audiences: party, named people, desk. The actor is dropped, and
 *     nobody is told twice;
 *  3. channels: the person's choices within what the notice allows, then
 *     reachable addresses only, then the SMS allowance of the licence;
 *  4. one delivery row per person and channel, written before the send.
 *     Nobody to tell is one `unaddressed` row: a visible fact.
 *
 * Deliver, once per row: send through the channel's adapter and settle the
 * row. An urgent notice that fails on one channel tries its fallback.
 */
final class NoticeDispatcher
{
    /** Domains reserved for examples and tests, and numbers that are not international. */
    private const string UNREACHABLE_EMAIL = '/@(example\.(com|net|org)|[^@]+\.(example|test|invalid|localhost))$/i';

    private const string PHONE = '/^\+[1-9][0-9]{7,14}$/';

    public function __construct(
        private readonly NoticeCatalogue $catalogue,
        private readonly ModuleActivation $activation,
        private readonly ScopeHierarchy $hierarchy,
        private readonly Licences $licences,
        private readonly ChannelAdapters $channels,
        private readonly Container $container,
        private readonly Clock $clock,
    ) {}

    public function resolve(string $requestId): void
    {
        $request = DB::table('notification_requests')->where('id', $requestId)->first();

        if ($request === null) {
            return;
        }

        DB::transaction(function () use ($request): void {
            $first = DB::table('notification_resolutions')->insertOrIgnore([
                'request_id' => $request->id,
                'resolved_at' => $this->clock->now(),
            ]);

            if ($first === 0) {
                return;
            }

            $this->resolveRequest($request);
        });
    }

    public function deliver(string $deliveryId): void
    {
        $row = DB::table('notification_deliveries')->where('id', $deliveryId)->where('status', DeliveryStatus::Queued->value)->first();

        if ($row === null) {
            return;
        }

        $channel = Channel::from(self::text($row, 'channel'));
        $receipt = $this->channels->for($channel)->send(
            new RenderedNotice(self::text($row, 'id'), self::text($row, 'topic_key'), self::text($row, 'subject'),
                self::text($row, 'body'), self::maybe($row, 'short'), self::maybe($row, 'action_url')),
            self::recipientOf($row),
        );

        DB::transaction(function () use ($row, $channel, $receipt): void {
            DB::table('notification_deliveries')->where('id', $row->id)->where('status', DeliveryStatus::Queued->value)->update([
                'status' => $receipt->status->value,
                'reason' => $receipt->reason,
                'provider_ref' => $receipt->providerRef,
                'attempts' => DB::raw('attempts + 1'),
                'settled_at' => $this->clock->now(),
            ]);

            if ($channel->isPaid() && $receipt->status !== DeliveryStatus::Failed) {
                DB::table('notification_usage')->insertOrIgnore([
                    'id' => Ids::next(),
                    'delivery_id' => $row->id,
                    'channel' => $channel->value,
                    'units' => $receipt->units,
                    'used_at' => $this->clock->now(),
                ]);
            }

            if ($receipt->status === DeliveryStatus::Failed) {
                $this->fallBack($row);
            }
        });
    }

    /** The send kept failing: settle the row as failed, and try the fallback of an urgent notice. */
    public function failed(string $deliveryId, string $reason): void
    {
        DB::transaction(function () use ($deliveryId, $reason): void {
            $row = DB::table('notification_deliveries')->where('id', $deliveryId)->where('status', DeliveryStatus::Queued->value)->lockForUpdate()->first();

            if ($row === null) {
                return;
            }

            DB::table('notification_deliveries')->where('id', $deliveryId)->update([
                'status' => DeliveryStatus::Failed->value,
                'reason' => mb_substr($reason, 0, 120),
                'settled_at' => $this->clock->now(),
            ]);

            $this->fallBack($row);
        });
    }

    private function resolveRequest(stdClass $request): void
    {
        $key = self::text($request, 'topic_key');
        $base = $this->baseRow($request);

        if (! $this->catalogue->has($key)) {
            $this->marker($base, DeliveryStatus::Suppressed, 'not_in_build');

            return;
        }

        $notice = $this->catalogue->notice($key);
        $class = $this->catalogue->topic($key);
        $payload = json_decode(self::text($request, 'payload'), true, 32, JSON_THROW_ON_ERROR);
        $topic = $class::fromPayload(self::stringKeys($payload));
        $scope = ScopeRef::of(ScopeType::from(self::text($request, 'scope_type')), self::maybe($request, 'scope_id'));

        if (! $this->activation->isActive($this->catalogue->ownerOf($key), $scope)) {
            $this->marker($base, DeliveryStatus::Suppressed, 'module_inactive');

            return;
        }

        $actorId = self::maybe($request, 'actor_user_id');
        $actor = $actorId !== null ? User::query()->find($actorId) : null;
        $tokens = [
            ...$topic->tokens(),
            'institution' => app(SchoolProfile::class)->name(),
            'actor' => $actor instanceof User ? $actor->name : null,
        ];

        $words = [
            'subject' => Words::fill($notice->subjectText(), $tokens),
            'body' => implode("\n\n", array_map(fn (string $line): string => Words::fill($line, $tokens), $notice->lines())),
            'short' => $notice->shortText() !== null ? Words::fill($notice->shortText(), $tokens) : null,
            'action_url' => $notice->actionPath() !== null
                ? rtrim(config()->string('acadly.frontend_url'), '/').Words::fill($notice->actionPath(), $tokens)
                : null,
        ];

        $written = 0;
        $smsLeft = $this->smsAllowance($notice);

        foreach ($this->audiences($notice, $topic, $request, $scope) as [$recipient, $audience, $channels]) {
            if ($actorId !== null && $recipient->userId === $actorId) {
                continue;
            }

            foreach ($channels as $channel) {
                if (! $this->reachable($recipient, $channel)) {
                    continue;
                }

                $status = DeliveryStatus::Queued;
                $reason = null;

                if ($channel === Channel::Sms && $smsLeft !== null) {
                    if ($smsLeft <= 0) {
                        $status = DeliveryStatus::Suppressed;
                        $reason = 'quota';
                    } else {
                        $smsLeft--;
                    }
                }

                $written += $this->writeDelivery([...$base, ...$words], $recipient, $audience, $channel, $status, $reason);
            }
        }

        if ($written === 0) {
            $this->marker([...$base, ...$words], DeliveryStatus::Unaddressed, null);
        }
    }

    /**
     * Party, then named people, then desk. The first audience that names a person keeps them.
     *
     * @return list<array{Recipient, string, list<Channel>}>
     */
    private function audiences(Notice $notice, Topic $topic, stdClass $request, ScopeRef $scope): array
    {
        /** @var array<string, array{Recipient, string, list<Channel>}> $found */
        $found = [];

        foreach ($notice->parties() as $resolverClass) {
            $resolver = $this->container->make($resolverClass);

            if (! $resolver instanceof PartyResolver) {
                throw new LogicException("[{$resolverClass}] is not a party resolver.");
            }

            foreach ($resolver->resolve($topic) as $recipient) {
                $found[$recipient->key()] ??= [$recipient, 'party', $this->preferred($notice, $recipient)];
            }
        }

        $people = json_decode(self::text($request, 'people'), true, 4, JSON_THROW_ON_ERROR);
        $ids = array_values(array_filter(is_array($people) ? $people : [], 'is_string'));

        foreach ($ids === [] ? [] : User::query()->whereIn('id', $ids)->get() as $person) {
            if ($person->isActive()) {
                $recipient = Recipient::user($person);
                $found[$recipient->key()] ??= [$recipient, 'person', $this->preferred($notice, $recipient)];
            }
        }

        if ($notice->hasDesk()) {
            foreach ($this->desk($notice, $scope) as [$user, $channels]) {
                $recipient = Recipient::user($user);
                $found[$recipient->key()] ??= [$recipient, 'desk', $channels];
            }
        }

        return array_values($found);
    }

    /**
     * Staff subscribed to the topic here or at a scope that contains it. Never found by role.
     *
     * @return list<array{User, list<Channel>}>
     */
    private function desk(Notice $notice, ScopeRef $scope): array
    {
        $places = [$scope, ...$this->hierarchy->ancestors($scope)];

        if (! in_array(ScopeRef::institution(), $places, false)) {
            $places[] = ScopeRef::institution();
        }

        $rows = DB::table('notification_subscriptions')
            ->where('topic_key', $notice->key)
            ->whereNull('ended_at')
            ->where(function (Builder $query) use ($places): void {
                foreach ($places as $place) {
                    $query->orWhere(fn (Builder $q): Builder => $q->where('scope_type', $place->type->value)
                        ->where('scope_id', $place->id));
                }
            })
            ->get(['user_id', 'channels']);

        $users = User::query()->whereIn('id', $rows->pluck('user_id')->all())->get()->keyBy('id');
        $out = [];

        foreach ($rows as $row) {
            $user = $users->get(self::text($row, 'user_id'));

            if (! $user instanceof User || ! $user->isActive()) {
                continue;
            }

            $chosen = self::maybe($row, 'channels');
            $channels = $chosen !== null
                ? array_values(array_filter(array_map(
                    fn (mixed $value): ?Channel => is_string($value) ? Channel::tryFrom($value) : null,
                    (array) json_decode($chosen, true, 4, JSON_THROW_ON_ERROR),
                )))
                : $notice->defaultChannels();

            $out[] = [$user, array_values(array_filter($channels, fn (Channel $c): bool => in_array($c, $notice->allowedChannels(), true)))];
        }

        return $out;
    }

    /**
     * The default channels, less the ones the person switched off where the
     * notice lets them, plus the allowed ones they switched on.
     *
     * @return list<Channel>
     */
    private function preferred(Notice $notice, Recipient $recipient): array
    {
        $channels = $notice->defaultChannels();

        if ($recipient->userId === null || $notice->optOutRule() === OptOut::Never) {
            return $channels;
        }

        $choices = DB::table('notification_preferences')
            ->where('user_id', $recipient->userId)
            ->where('topic_key', $notice->key)
            ->pluck('enabled', 'channel');

        foreach ($notice->allowedChannels() as $channel) {
            $choice = $choices->get($channel->value);

            if ($choice === null) {
                continue;
            }

            $channels = (bool) $choice
                ? array_values(array_unique([...$channels, $channel], SORT_REGULAR))
                : array_values(array_filter($channels, fn (Channel $c): bool => $c !== $channel));
        }

        return $channels;
    }

    private function reachable(Recipient $recipient, Channel $channel): bool
    {
        $address = $recipient->address($channel);

        return match ($channel) {
            Channel::InApp => $address !== null,
            Channel::Email => $address !== null && filter_var($address, FILTER_VALIDATE_EMAIL) !== false && preg_match(self::UNREACHABLE_EMAIL, $address) !== 1,
            Channel::Sms => $address !== null && preg_match(self::PHONE, $address) === 1,
        };
    }

    /** Text messages left this month under the licence, or null when there is no limit. Urgent notices are never capped. */
    private function smsAllowance(Notice $notice): ?int
    {
        $limit = $this->licences->current()->limits['sms_per_month'] ?? null;

        if ($limit === null || $notice->isUrgent()) {
            return null;
        }

        $used = DB::table('notification_usage')
            ->where('channel', Channel::Sms->value)
            ->where('used_at', '>=', $this->clock->now()->startOfMonth())
            ->sum('units');

        $queued = DB::table('notification_deliveries')
            ->where('channel', Channel::Sms->value)
            ->where('status', DeliveryStatus::Queued->value)
            ->count();

        return $limit - (int) $used - $queued;
    }

    /** @param  array<string, mixed>  $row */
    private function writeDelivery(array $row, Recipient $recipient, string $audience, Channel $channel, DeliveryStatus $status, ?string $reason): int
    {
        $id = Ids::next();

        $inserted = DB::table('notification_deliveries')->insertOrIgnore([
            ...$row,
            'id' => $id,
            'audience' => $audience,
            'recipient_key' => $recipient->key(),
            'recipient_user_id' => $recipient->userId,
            'recipient_name' => mb_substr($recipient->name, 0, 255),
            'recipient_email' => $recipient->email,
            'recipient_phone' => $recipient->phone,
            'channel' => $channel->value,
            'status' => $status->value,
            'reason' => $reason,
            'queued_at' => $this->clock->now(),
            'settled_at' => $status === DeliveryStatus::Queued ? null : $this->clock->now(),
        ]);

        if ($inserted > 0 && $status === DeliveryStatus::Queued) {
            DB::afterCommit(fn () => DeliverNotice::dispatch($id));
        }

        return $inserted;
    }

    /** @param  array<string, mixed>  $row */
    private function marker(array $row, DeliveryStatus $status, ?string $reason): void
    {
        DB::table('notification_deliveries')->insertOrIgnore([
            'subject' => '',
            'body' => '',
            ...$row,
            'id' => Ids::next(),
            'audience' => 'none',
            'recipient_key' => 'none',
            'channel' => 'none',
            'status' => $status->value,
            'reason' => $reason,
            'queued_at' => $this->clock->now(),
            'settled_at' => $this->clock->now(),
        ]);
    }

    /** An urgent notice that failed on one channel goes out on the next one in its chain. */
    private function fallBack(stdClass $row): void
    {
        $key = self::text($row, 'topic_key');

        if (! $this->catalogue->has($key) || ! $this->catalogue->notice($key)->isUrgent()) {
            return;
        }

        $recipient = self::recipientOf($row);
        $copy = [];

        foreach (['request_id', 'topic_key', 'subject', 'body', 'short', 'action_url', 'subject_type', 'subject_id', 'scope_type', 'scope_id', 'dedupe_key'] as $column) {
            $copy[$column] = $row->{$column} ?? null;
        }

        foreach ($this->catalogue->notice($key)->fallback() as $channel) {
            if ($channel->value === self::text($row, 'channel') || ! $this->reachable($recipient, $channel)) {
                continue;
            }

            if ($this->writeDelivery($copy, $recipient, self::text($row, 'audience'), $channel, DeliveryStatus::Queued, null) > 0) {
                return;
            }
        }
    }

    /** @return array<string, mixed> */
    private function baseRow(stdClass $request): array
    {
        return [
            'request_id' => $request->id,
            'topic_key' => $request->topic_key,
            'subject_type' => $request->subject_type,
            'subject_id' => $request->subject_id,
            'scope_type' => $request->scope_type,
            'scope_id' => $request->scope_id,
            'dedupe_key' => $request->dedupe_key,
        ];
    }

    private static function recipientOf(stdClass $row): Recipient
    {
        return new Recipient(self::maybe($row, 'recipient_name') ?? '', self::maybe($row, 'recipient_user_id'),
            self::maybe($row, 'recipient_email'), self::maybe($row, 'recipient_phone'));
    }

    private static function text(stdClass $row, string $column): string
    {
        $value = $row->{$column} ?? null;

        return is_string($value) ? $value : throw new LogicException("The column [{$column}] is not text.");
    }

    private static function maybe(stdClass $row, string $column): ?string
    {
        $value = $row->{$column} ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    private static function stringKeys(mixed $decoded): array
    {
        $out = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
