<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application;

use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Contracts\StudentPlaces;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** The Programmes contract for addressing students by place (D-101). */
final class StudentPlacesReader implements StudentPlaces
{
    public function of(string $userId): ?array
    {
        $s = StudentAccountDirectory::studentOf($userId);

        return $s === null ? null : ['division_id' => $s['division_id'], 'faculty_id' => $s['faculty_id'], 'department_id' => $s['department_id'],
            'programme_id' => $s['programme_id'], 'year_of_study' => $s['year_of_study']];
    }

    public function structure(string $divisionId): array
    {
        $list = fn (Builder $q): array => array_values(array_map(fn (object $r): array => ['id' => Row::text($r, 'id'), 'name' => Row::text($r, 'name')], $q->get()->all()));

        return [
            'faculties' => $list(DB::table('programme_faculties')->where('division_id', $divisionId)->orderBy('name')->select(['id', 'name'])),
            'departments' => $list(DB::table('programme_departments as d')->join('programme_faculties as f', 'f.id', '=', 'd.faculty_id')->where('f.division_id', $divisionId)
                ->orderBy('d.name')->select(['d.id', 'd.name'])),
            'programmes' => $list(DB::table('programmes')->where('division_id', $divisionId)->orderBy('code')->selectRaw("id, code || ' · ' || name as name")),
            'years' => array_values(array_map('intval', DB::table('programme_students')->where('division_id', $divisionId)->whereNull('ended_at')->distinct()->orderBy('year_of_study')
                ->pluck('year_of_study')->filter(fn ($v): bool => is_numeric($v))->all())),
        ];
    }

    public function count(string $divisionId, string $audience, ?string $id, ?int $year): int
    {
        $q = DB::table('programme_students as s')->join('programmes as p', 'p.id', '=', 's.programme_id')->join('programme_departments as d', 'd.id', '=', 'p.department_id')
            ->where('s.division_id', $divisionId)->whereNull('s.ended_at')->where('s.status', 'active');

        return match ($audience) {
            'faculty' => $q->where('d.faculty_id', $id)->count(),
            'department' => $q->where('p.department_id', $id)->count(),
            'programme' => $q->where('s.programme_id', $id)->count(),
            'year' => $q->where('s.year_of_study', $year)->count(),
            'staff' => 0,
            default => $q->count(),
        };
    }
}
