<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application\Registration;

use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * The registration dates of a semester (D-098): opens, closes, late
 * registration with its fee, and the end of add and drop.
 *
 * @phpstan-type Window array{id: string, opens_on: string, closes_on: string, late_until: string, add_drop_until: string, late_fee: int, fees_share: int, started_at: string|null}
 */
final class Windows
{
    public function __construct(private readonly Clock $clock) {}

    /** @return Window|null */
    public static function of(string $periodId): ?array
    {
        $w = DB::table('programme_registration_windows')->where('period_id', $periodId)->first();

        return $w === null ? null : ['id' => Row::text($w, 'id'), 'opens_on' => Row::text($w, 'opens_on'), 'closes_on' => Row::text($w, 'closes_on'),
            'late_until' => Row::text($w, 'late_until'), 'add_drop_until' => Row::text($w, 'add_drop_until'), 'late_fee' => Row::int($w, 'late_fee_minor'),
            'fees_share' => Row::int($w, 'fees_share'), 'started_at' => Row::maybeText($w, 'started_at')];
    }

    /** 'none', 'before', 'open', 'late' or 'closed', for today. */
    public function phase(string $periodId): string
    {
        $w = self::of($periodId);
        $today = $this->clock->today();

        return match (true) {
            $w === null => 'none',
            $today < $w['opens_on'] => 'before',
            $today <= $w['closes_on'] => 'open',
            $today <= $w['late_until'] => 'late',
            default => 'closed',
        };
    }

    public function addDropOpen(string $periodId): bool
    {
        $w = self::of($periodId);

        return $w !== null && $this->clock->today() <= $w['add_drop_until'];
    }
}
