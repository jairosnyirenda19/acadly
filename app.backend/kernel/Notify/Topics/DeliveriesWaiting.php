<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Topics;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\KernelCategory;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\NotifyPermission;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;

/**
 * Messages have been waiting to go out for more than an hour (04 §7). Raised
 * by `notify:sweep`, at most once an hour, to the staff who look after delivery.
 */
final readonly class DeliveriesWaiting implements Topic
{
    public function __construct(
        public int $count,
        public string $hour,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('notify.deliveries_waiting')
            ->named('Messages waiting to be sent')
            ->category(KernelCategory::System)
            ->facts('count')
            ->subject('Messages are waiting to be sent')
            ->line(':count messages have been waiting to be sent for more than one hour.')
            ->line('Check the delivery settings and the message provider.')
            ->toDesk()
            ->actedOnWith(NotifyPermission::Manage)
            ->channels(default: [Channel::InApp, Channel::Email], allowed: [Channel::InApp, Channel::Email]);
    }

    public function subject(): SubjectRef
    {
        return SubjectRef::none();
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::institution();
    }

    public function tokens(): array
    {
        return ['count' => (string) $this->count];
    }

    public function dedupeKey(): string
    {
        return 'deliveries_waiting:'.$this->hour;
    }

    public function toPayload(): array
    {
        return ['count' => $this->count, 'hour' => $this->hour];
    }

    public static function fromPayload(array $payload): static
    {
        $count = $payload['count'] ?? 0;
        $hour = $payload['hour'] ?? '';

        return new self(is_int($count) ? $count : 0, is_string($hour) ? $hour : '');
    }
}
