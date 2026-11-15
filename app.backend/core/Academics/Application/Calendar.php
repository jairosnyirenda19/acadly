<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/** The academic calendar and the classes, read from the Academics tables. */
final class Calendar implements AcademicCalendar
{
    public function __construct(
        private readonly SchoolDirectory $school,
        private readonly Clock $clock,
    ) {}

    public function currentYear(?string $day = null): ?array
    {
        $day ??= $this->clock->now()->toDateString();
        $year = DB::table('academic_years')->where('starts_on', '<=', $day)->where('ends_on', '>=', $day)->first()
            ?? DB::table('academic_years')->orderBy('starts_on')->first();

        if ($year === null) {
            return null;
        }

        $id = Row::text($year, 'id');

        return [
            'id' => $id,
            'name' => Row::text($year, 'name'),
            'starts_on' => self::day(Row::text($year, 'starts_on')),
            'ends_on' => self::day(Row::text($year, 'ends_on')),
            'periods' => array_values(DB::table('academic_periods')->where('year_id', $id)->orderBy('position')->get()
                ->map(fn (object $period): array => [
                    'id' => Row::text($period, 'id'),
                    'name' => Row::text($period, 'name'),
                    'starts_on' => self::day(Row::text($period, 'starts_on')),
                    'ends_on' => self::day(Row::text($period, 'ends_on')),
                ])->all()),
        ];
    }

    public function classCount(string $yearId): int
    {
        return DB::table('academic_classes')->where('year_id', $yearId)->whereNull('ended_at')->count();
    }

    public function everyOfferingHasClasses(string $yearId): bool
    {
        $offerings = $this->school->offerings();

        if ($offerings === []) {
            return false;
        }

        foreach ($offerings as $offering) {
            $has = DB::table('academic_classes')->where('year_id', $yearId)->whereNull('ended_at')
                ->where('division_id', $offering['division_id'])->where('campus_id', $offering['campus_id'])->exists();

            if (! $has) {
                return false;
            }
        }

        return true;
    }

    /**
     * The classes of one offering in a year, by level code.
     *
     * @return array<string, list<string|null>> level code => streams (null for a level with no stream)
     */
    public function classesOf(string $yearId, string $divisionId, string $campusId): array
    {
        $out = [];

        foreach (DB::table('academic_classes')->where('year_id', $yearId)->where('division_id', $divisionId)
            ->where('campus_id', $campusId)->whereNull('ended_at')->orderBy('position')->get(['level_code', 'stream']) as $row) {
            $out[Row::text($row, 'level_code')][] = Row::maybeText($row, 'stream');
        }

        return $out;
    }

    private static function day(string $value): string
    {
        return substr($value, 0, 10);
    }
}
