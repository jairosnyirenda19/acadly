<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Acadly\Kernel\Events\DomainEvent;
use Acadly\Kernel\Events\EventConsumer;
use Acadly\Kernel\Events\EventRecord;
use RuntimeException;

/** Counts what it receives. Told to fail, it throws. */
final class RecordingConsumer implements EventConsumer
{
    /** @var list<DomainEvent> */
    public static array $seen = [];

    public static bool $fail = false;

    public static function consumerKey(): string
    {
        return 'listener.recorder';
    }

    public function handle(DomainEvent $event, EventRecord $record): void
    {
        if (self::$fail) {
            throw new RuntimeException('The consumer failed.');
        }

        self::$seen[] = $event;
    }
}
