<?php

declare(strict_types=1);

namespace Acadly\Kernel\Operations;

use Acadly\Kernel\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The scheduler and the queue worker each leave a heartbeat every minute
 * (D-112). Each is alive while its last beat is recent enough.
 */
final class Heartbeats
{
    public const string SCHEDULER = 'scheduler';

    public const string QUEUE = 'queue';

    /** How old a beat may be, in minutes, before the process counts as stopped. */
    public const array LIMITS = [self::SCHEDULER => 3, self::QUEUE => 5];

    public function __construct(private readonly Clock $clock) {}

    public function beat(string $name): void
    {
        DB::table('system_heartbeats')->upsert([['name' => $name, 'beat_at' => $this->clock->now()]], ['name'], ['beat_at']);
    }

    /** @return array<string, bool> each watched process => alive */
    public function alive(): array
    {
        $beats = DB::table('system_heartbeats')->pluck('beat_at', 'name')->all();
        $now = CarbonImmutable::parse($this->clock->now());
        $out = [];

        foreach (self::LIMITS as $name => $minutes) {
            $at = $beats[$name] ?? null;
            $out[$name] = is_string($at) && CarbonImmutable::parse($at)->greaterThanOrEqualTo($now->subMinutes($minutes));
        }

        return $out;
    }

    /** Whether the readiness check watches the scheduler and the worker: on every server, off on a developer's machine and in tests. */
    public static function watched(): bool
    {
        return (bool) config('acadly.operations.watch_workers');
    }
}
