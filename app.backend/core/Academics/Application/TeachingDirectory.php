<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application;

use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/** Subjects, teaching and terms, read from the Academics tables. */
final class TeachingDirectory implements Teaching
{
    private const array PERIOD_COLUMNS = ['id', 'name', 'division_id', 'year_id', 'starts_on', 'ends_on'];

    public function period(string $id): ?array
    {
        if (! Ids::isValid($id)) {
            return null;
        }

        $row = DB::table('academic_periods')->where('id', $id)->first(self::PERIOD_COLUMNS);

        return $row === null ? null : self::periodOf($row);
    }

    public function periodOn(string $divisionId, string $day): ?array
    {
        $row = DB::table('academic_periods')->where('division_id', $divisionId)
            ->where('starts_on', '<=', $day)->where('ends_on', '>=', $day)->first(self::PERIOD_COLUMNS);

        return $row === null ? null : self::periodOf($row);
    }

    public function periodAt(string $divisionId, string $day): ?array
    {
        $row = DB::table('academic_periods')->where('division_id', $divisionId)->where('starts_on', '<=', $day)
            ->orderByDesc('starts_on')->first(self::PERIOD_COLUMNS);

        return $row === null ? null : self::periodOf($row);
    }

    public function subjects(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, Ids::isValid(...))));

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('academic_subjects')->whereIn('id', $ids)->get(['id', 'name', 'short', 'division_id']) as $row) {
            $out[Row::text($row, 'id')] = self::subjectOf($row);
        }

        return $out;
    }

    public function subjectsFor(string $divisionId, string $levelCode): array
    {
        return array_values(DB::table('academic_subjects')->where('division_id', $divisionId)->whereNull('retired_at')
            ->whereRaw('levels @> ?::jsonb', [json_encode([$levelCode], JSON_THROW_ON_ERROR)])
            ->orderBy('position')->orderBy('name')->get(['id', 'name', 'short', 'division_id'])
            ->map(fn (object $row): array => self::subjectOf($row))->all());
    }

    public function teaches(string $userId, string $classId, string $subjectId): bool
    {
        return DB::table('academic_teaching')->where('user_id', $userId)->where('class_id', $classId)
            ->where('subject_id', $subjectId)->whereNull('ended_at')->exists();
    }

    public function taughtBy(string $userId): array
    {
        return array_values(DB::table('academic_teaching')->where('user_id', $userId)->whereNotNull('subject_id')->whereNull('ended_at')
            ->get(['class_id', 'subject_id'])
            ->map(fn (object $row): array => ['class_id' => Row::text($row, 'class_id'), 'subject_id' => Row::text($row, 'subject_id')])->all());
    }

    public function teachersOf(array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }

        return array_values(DB::table('academic_teaching')->whereIn('class_id', array_values(array_unique($classIds)))
            ->whereNotNull('subject_id')->whereNull('ended_at')->get(['class_id', 'subject_id', 'user_id'])
            ->map(fn (object $row): array => ['class_id' => Row::text($row, 'class_id'), 'subject_id' => Row::text($row, 'subject_id'), 'user_id' => Row::text($row, 'user_id')])->all());
    }

    /** @return array{id: string, name: string, division_id: string, year_id: string, starts_on: string, ends_on: string} */
    private static function periodOf(object $row): array
    {
        return [
            'id' => Row::text($row, 'id'), 'name' => Row::text($row, 'name'), 'division_id' => Row::text($row, 'division_id'),
            'year_id' => Row::text($row, 'year_id'), 'starts_on' => substr(Row::text($row, 'starts_on'), 0, 10), 'ends_on' => substr(Row::text($row, 'ends_on'), 0, 10),
        ];
    }

    /** @return array{id: string, name: string, short: string, division_id: string} */
    private static function subjectOf(object $row): array
    {
        return ['id' => Row::text($row, 'id'), 'name' => Row::text($row, 'name'), 'short' => Row::text($row, 'short'), 'division_id' => Row::text($row, 'division_id')];
    }
}
