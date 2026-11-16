<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
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
        $day ??= $this->clock->today();
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
            'terms' => $this->termsOf($id),
        ];
    }

    /** @return array<string, list<array{id: string, name: string, starts_on: string, ends_on: string}>> division id => terms in order */
    private function termsOf(string $yearId): array
    {
        $terms = [];

        foreach (DB::table('academic_periods')->where('year_id', $yearId)->orderBy('position')->get() as $period) {
            $terms[Row::text($period, 'division_id')][] = [
                'id' => Row::text($period, 'id'),
                'name' => Row::text($period, 'name'),
                'starts_on' => self::day(Row::text($period, 'starts_on')),
                'ends_on' => self::day(Row::text($period, 'ends_on')),
            ];
        }

        return $terms;
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

    public function classInForce(string $classId): ?array
    {
        if (! Ids::isValid($classId)) {
            return null;
        }

        $row = DB::table('academic_classes')->where('id', $classId)->whereNull('ended_at')->first(self::CLASS_COLUMNS);

        return $row === null ? null : self::classOf($row);
    }

    public function classesInYear(string $yearId): array
    {
        return array_values(DB::table('academic_classes as k')
            ->join('institution_divisions as d', 'd.id', '=', 'k.division_id')
            ->join('institution_campuses as c', 'c.id', '=', 'k.campus_id')
            ->where('k.year_id', $yearId)->whereNull('k.ended_at')
            ->orderBy('d.position')->orderBy('c.position')->orderBy('k.position')
            ->get(array_map(fn (string $c): string => "k.{$c}", self::CLASS_COLUMNS))
            ->map(fn (object $row): array => self::classOf($row))->all());
    }

    public function classNames(array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('academic_classes as k')->join('academic_years as y', 'y.id', '=', 'k.year_id')
            ->whereIn('k.id', array_values(array_unique($classIds)))->get(['k.id', 'k.name', 'y.name as year']) as $row) {
            $out[Row::text($row, 'id')] = ['name' => Row::text($row, 'name'), 'year' => Row::text($row, 'year')];
        }

        return $out;
    }

    public function classTeacherId(string $classId): ?string
    {
        $row = DB::table('academic_teaching')->where('class_id', $classId)->whereNull('subject_id')->whereNull('ended_at')->first(['user_id']);

        return $row === null ? null : Row::text($row, 'user_id');
    }

    private const array CLASS_COLUMNS = ['id', 'name', 'year_id', 'division_id', 'campus_id', 'level_code'];

    /** @return array{id: string, name: string, year_id: string, division_id: string, campus_id: string, level_code: string} */
    private static function classOf(object $row): array
    {
        return [
            'id' => Row::text($row, 'id'), 'name' => Row::text($row, 'name'), 'year_id' => Row::text($row, 'year_id'),
            'division_id' => Row::text($row, 'division_id'), 'campus_id' => Row::text($row, 'campus_id'), 'level_code' => Row::text($row, 'level_code'),
        ];
    }

    private static function day(string $value): string
    {
        return substr($value, 0, 10);
    }
}
