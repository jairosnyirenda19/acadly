<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Application\Registration\RegistrationChecks;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** The Programmes contract the lecture timetable reads (D-102). */
final class CourseTeachingReader implements CourseTeaching
{
    private const string NONE = '00000000-0000-0000-0000-000000000000';

    public function __construct(private readonly EnrolmentDirectory $learners) {}

    public function offerings(string $divisionId, string $periodId): array
    {
        $rows = DB::table('programme_offerings as o')->join('programme_courses as c', 'c.id', '=', 'o.course_id')->join('programme_departments as d', 'd.id', '=', 'c.department_id')
            ->where('o.division_id', $divisionId)->where('o.period_id', $periodId)->orderBy('c.code')->orderBy('o.group_label')
            ->get(['o.id', 'o.group_label', 'o.course_id', 'c.code', 'c.title', 'c.credits', 'd.id as department_id', 'd.name as department', 'd.head_user_id']);
        $ids = array_values(array_map(fn (object $r): string => Row::text($r, 'id'), $rows->all()));
        $taken = RegistrationChecks::taken($ids);
        $lecturers = [];
        foreach (DB::table('programme_offering_staff as s')->join('users as u', 'u.id', '=', 's.user_id')->whereIn('s.offering_id', $ids ?: [self::NONE])->where('s.role', 'lecturer')
            ->orderBy('u.name')->get(['s.offering_id', 'u.id', 'u.name']) as $s) {
            $lecturers[Row::text($s, 'offering_id')][] = ['id' => Row::text($s, 'id'), 'name' => Row::text($s, 'name')];
        }
        $out = [];
        foreach ($rows as $r) {
            $id = Row::text($r, 'id');
            $out[] = ['id' => $id, 'course_id' => Row::text($r, 'course_id'), 'code' => Row::text($r, 'code'), 'title' => Row::text($r, 'title'), 'group' => Row::text($r, 'group_label'),
                'department_id' => Row::text($r, 'department_id'), 'department' => Row::text($r, 'department'), 'head_id' => Row::maybeText($r, 'head_user_id'), 'credits' => Row::int($r, 'credits'),
                'lecturers' => $lecturers[$id] ?? [], 'taken' => $taken[$id] ?? 0];
        }

        return $out;
    }

    public function students(string $offeringId): array
    {
        $ids = array_values(array_unique(array_map(fn (object $r): string => Row::text($r, 'learner_id'), $this->holding()->where('rc.offering_id', $offeringId)->get(['r.learner_id'])->all())));
        if ($ids === []) {
            return [];
        }
        $names = $this->learners->names($ids);
        $accounts = DB::table('programme_student_accounts')->whereIn('learner_id', $ids)->get(['learner_id', 'user_id', 'phone', 'suspended_at'])->keyBy('learner_id');
        $out = [];
        foreach ($ids as $id) {
            $a = $accounts->get($id);
            $out[] = ['learner_id' => $id, 'name' => $names[$id] ?? '', 'number' => StudentAccountDirectory::numberOf($id),
                'user_id' => is_object($a) && ($a->suspended_at ?? null) === null ? Row::maybeText($a, 'user_id') : null, 'phone' => is_object($a) ? Row::maybeText($a, 'phone') : null];
        }
        usort($out, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    public function offeringsOf(string $learnerId, string $periodId): array
    {
        return array_values(array_unique(array_map(fn (object $r): string => Row::text($r, 'offering_id'),
            $this->holding()->where('r.learner_id', $learnerId)->where('r.period_id', $periodId)->get(['rc.offering_id'])->all())));
    }

    public function studentOf(string $userId): ?array
    {
        $s = StudentAccountDirectory::studentOf($userId);

        return $s === null ? null : ['learner_id' => $s['learner_id'], 'division_id' => $s['division_id']];
    }

    public function cohorts(string $divisionId, string $periodId): array
    {
        $position = DB::table('academic_periods')->where('id', $periodId)->value('position');
        if (! is_numeric($position)) {
            return [];
        }
        $offerings = [];
        foreach (DB::table('programme_offerings')->where('period_id', $periodId)->where('division_id', $divisionId)->get(['id', 'course_id']) as $o) {
            $offerings[Row::text($o, 'course_id')][] = Row::text($o, 'id');
        }
        $out = [];
        foreach (DB::table('programmes')->where('division_id', $divisionId)->whereNull('retired_at')->orderBy('code')->get(['id', 'code', 'years']) as $p) {
            $curriculum = DB::table('programme_curricula')->where('programme_id', Row::text($p, 'id'))->whereNotNull('released_at')->orderByDesc('released_at')->value('id');
            if (! is_string($curriculum)) {
                continue;
            }
            for ($year = 1; $year <= Row::int($p, 'years'); $year++) {
                $list = [];
                foreach (DB::table('programme_curriculum_courses')->where('curriculum_id', $curriculum)->where('year', $year)->where('semester', (int) $position)->pluck('course_id') as $course) {
                    array_push($list, ...($offerings[is_string($course) ? $course : ''] ?? []));
                }
                if ($list !== []) {
                    $out[] = ['id' => Row::text($p, 'id').':'.$year, 'label' => Row::text($p, 'code').' · Year '.$year, 'offerings' => array_values(array_unique($list))];
                }
            }
        }

        return $out;
    }

    public function studentFile(string $learnerId): ?array
    {
        $row = DB::table('programme_students as s')->join('programmes as p', 'p.id', '=', 's.programme_id')->where('s.learner_id', $learnerId)
            ->orderByRaw('s.ended_at is null desc')->orderByDesc('s.created_at')->first(['s.division_id', 's.programme_id', 's.year_of_study', 'p.code', 'p.name', 'p.award']);
        if ($row === null) {
            return null;
        }

        return ['learner_id' => $learnerId, 'name' => $this->learners->names([$learnerId])[$learnerId] ?? '', 'number' => StudentAccountDirectory::numberOf($learnerId),
            'programme_id' => Row::text($row, 'programme_id'), 'programme_code' => Row::text($row, 'code'), 'programme' => Row::text($row, 'name'), 'award' => Row::text($row, 'award'),
            'year_of_study' => Row::int($row, 'year_of_study'), 'division_id' => Row::text($row, 'division_id')];
    }

    /** The courses of every registration that holds a place, at its version in force. */
    private function holding(): Builder
    {
        return DB::table('programme_registration_courses as rc')->join('programme_registrations as r', function (JoinClause $j): void {
            $j->on('r.id', '=', 'rc.registration_id')->on('r.version', '=', 'rc.version');
        })->whereIn('r.state', RegistrationChecks::HOLDING);
    }
}
