<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application;

use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** What the Programmes screens read (D-097). Callers check the permission first. */
final class ProgrammesViews
{
    /**
     * Faculties with their departments, and the programmes.
     *
     * @return array{faculties: list<array{id: string, name: string, code: string, retired: bool, departments: list<array{id: string, name: string, code: string, prefixes: list<string>, head: array{id: string, name: string}|null, retired: bool, programmes: int, courses: int, staff: int}>}>, programmes: list<array{id: string, department_id: string, code: string, name: string, award: string, years: int, credits: int, open: bool, retired: bool, released: bool}>}
     */
    public static function structure(string $divisionId): array
    {
        $faculties = [];
        $byFaculty = [];

        $departments = DB::table('programme_departments as d')->join('programme_faculties as f', 'f.id', '=', 'd.faculty_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.head_user_id')->where('f.division_id', $divisionId)->orderBy('d.name')
            ->get(['d.id', 'd.faculty_id', 'd.name', 'd.code', 'd.prefixes', 'd.retired_at', 'u.id as head_id', 'u.name as head_name']);
        $programmes = self::counted(DB::table('programmes')->where('division_id', $divisionId)->whereNull('retired_at')->groupBy('department_id')->selectRaw('department_id, count(*) as n')->get());
        $courses = self::counted(DB::table('programme_courses')->where('division_id', $divisionId)->whereNull('retired_at')->groupBy('department_id')->selectRaw('department_id, count(*) as n')->get());
        $staff = self::counted(DB::table('programme_offering_staff as s')->join('programme_offerings as o', 'o.id', '=', 's.offering_id')->join('programme_courses as c', 'c.id', '=', 'o.course_id')
            ->where('o.division_id', $divisionId)->groupBy('c.department_id')->selectRaw('c.department_id, count(distinct s.user_id) as n')->get());

        foreach ($departments as $d) {
            $id = Row::text($d, 'id');
            $byFaculty[Row::text($d, 'faculty_id')][] = ['id' => $id, 'name' => Row::text($d, 'name'), 'code' => Row::text($d, 'code'),
                'prefixes' => self::prefixes($d),
                'head' => Row::maybeText($d, 'head_id') !== null ? ['id' => Row::text($d, 'head_id'), 'name' => Row::text($d, 'head_name')] : null,
                'retired' => Row::maybeText($d, 'retired_at') !== null, 'programmes' => $programmes[$id] ?? 0, 'courses' => $courses[$id] ?? 0, 'staff' => $staff[$id] ?? 0];
        }

        foreach (DB::table('programme_faculties')->where('division_id', $divisionId)->orderBy('position')->get() as $f) {
            $faculties[] = ['id' => Row::text($f, 'id'), 'name' => Row::text($f, 'name'), 'code' => Row::text($f, 'code'), 'retired' => Row::maybeText($f, 'retired_at') !== null,
                'departments' => $byFaculty[Row::text($f, 'id')] ?? []];
        }

        $released = [];
        foreach (DB::table('programme_curricula')->whereNotNull('released_at')->get(['programme_id']) as $r) {
            $released[Row::text($r, 'programme_id')] = true;
        }
        $list = [];
        foreach (DB::table('programmes')->where('division_id', $divisionId)->orderBy('name')->get() as $p) {
            $list[] = ['id' => Row::text($p, 'id'), 'department_id' => Row::text($p, 'department_id'), 'code' => Row::text($p, 'code'), 'name' => Row::text($p, 'name'),
                'award' => Row::text($p, 'award'), 'years' => Row::int($p, 'years'), 'credits' => Row::int($p, 'credits'), 'open' => (bool) ($p->open_for_intake ?? false),
                'retired' => Row::maybeText($p, 'retired_at') !== null, 'released' => isset($released[Row::text($p, 'id')])];
        }

        return ['faculties' => $faculties, 'programmes' => $list];
    }

    /**
     * One programme, its versions, and one version's courses with the checks.
     *
     * @return array{programme: array{id: string, department_id: string, department: string, faculty: string, code: string, name: string, award: string, years: int, semesters: int, credits: int, min_load: int, max_load: int, open: bool, retired: bool}, versions: list<array{id: string, label: string, released_at: string|null}>, version: array{id: string, label: string, released: bool, groups: list<array{id: string, year: int, semester: int, label: string, choose: int}>, courses: list<array{course_id: string, code: string, title: string, credits: int, retired: bool, year: int, semester: int, group_id: string|null, needs: list<array{id: string, code: string}>}>, problems: list<array<string, string|int>>, years: array<int, int>, slots: array<string, int>, total: int}|null}|null
     */
    public static function programme(string $divisionId, string $programmeId, ?string $versionId): ?array
    {
        $p = DB::table('programmes as p')->join('programme_departments as d', 'd.id', '=', 'p.department_id')->join('programme_faculties as f', 'f.id', '=', 'd.faculty_id')
            ->where('p.id', $programmeId)->where('p.division_id', $divisionId)->first(['p.*', 'd.name as department', 'f.name as faculty']);

        if ($p === null) {
            return null;
        }

        $versions = [];
        foreach (DB::table('programme_curricula')->where('programme_id', $programmeId)->orderBy('created_at')->get() as $v) {
            $versions[] = ['id' => Row::text($v, 'id'), 'label' => Row::text($v, 'label'), 'released_at' => Row::maybeText($v, 'released_at')];
        }

        // The version asked for; else the draft; else the newest released.
        $chosen = null;
        foreach ($versions as $v) {
            if ($v['id'] === $versionId) {
                $chosen = $v;
            }
        }
        if ($chosen === null) {
            $drafts = array_values(array_filter($versions, fn ($v) => $v['released_at'] === null));
            $chosen = $drafts[0] ?? ($versions === [] ? null : $versions[count($versions) - 1]);
        }

        $version = null;
        if ($chosen !== null) {
            $parts = self::parts($chosen['id']);
            $version = ['id' => $chosen['id'], 'label' => $chosen['label'], 'released' => $chosen['released_at'] !== null, ...$parts,
                ...CurriculumChecks::run(self::shape($p), $parts['groups'], $parts['courses'])];
        }

        return ['programme' => ['id' => Row::text($p, 'id'), 'department_id' => Row::text($p, 'department_id'), 'department' => Row::text($p, 'department'),
            'faculty' => Row::text($p, 'faculty'), 'code' => Row::text($p, 'code'), 'name' => Row::text($p, 'name'), 'award' => Row::text($p, 'award'),
            ...self::shape($p), 'open' => (bool) ($p->open_for_intake ?? false), 'retired' => Row::maybeText($p, 'retired_at') !== null], 'versions' => $versions, 'version' => $version];
    }

    /**
     * The groups and courses of one curriculum version.
     *
     * @return array{groups: list<array{id: string, year: int, semester: int, label: string, choose: int}>, courses: list<array{course_id: string, code: string, title: string, credits: int, retired: bool, year: int, semester: int, group_id: string|null, needs: list<array{id: string, code: string}>}>}
     */
    public static function parts(string $curriculumId): array
    {
        $groups = [];
        foreach (DB::table('programme_elective_groups')->where('curriculum_id', $curriculumId)->orderBy('position')->get() as $g) {
            $groups[] = ['id' => Row::text($g, 'id'), 'year' => Row::int($g, 'year'), 'semester' => Row::int($g, 'semester'), 'label' => Row::text($g, 'label'), 'choose' => Row::int($g, 'choose')];
        }

        $rows = DB::table('programme_curriculum_courses as cc')->join('programme_courses as c', 'c.id', '=', 'cc.course_id')->where('cc.curriculum_id', $curriculumId)
            ->orderBy('cc.year')->orderBy('cc.semester')->orderBy('cc.position')
            ->get(['cc.course_id', 'c.code', 'c.title', 'c.credits', 'c.retired_at', 'cc.year', 'cc.semester', 'cc.group_id']);
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = Row::text($r, 'course_id');
        }
        $needs = self::needs($ids);

        $courses = [];
        foreach ($rows as $r) {
            $courses[] = ['course_id' => Row::text($r, 'course_id'), 'code' => Row::text($r, 'code'), 'title' => Row::text($r, 'title'), 'credits' => Row::int($r, 'credits'),
                'retired' => Row::maybeText($r, 'retired_at') !== null, 'year' => Row::int($r, 'year'), 'semester' => Row::int($r, 'semester'), 'group_id' => Row::maybeText($r, 'group_id'),
                'needs' => $needs[Row::text($r, 'course_id')] ?? []];
        }

        return ['groups' => $groups, 'courses' => $courses];
    }

    /** @return array{years: int, semesters: int, credits: int, min_load: int, max_load: int} */
    public static function shape(object $programme): array
    {
        return ['years' => Row::int($programme, 'years'), 'semesters' => Row::int($programme, 'semesters'), 'credits' => Row::int($programme, 'credits'),
            'min_load' => Row::int($programme, 'min_load'), 'max_load' => Row::int($programme, 'max_load')];
    }

    /**
     * The catalogue, with the departments to choose from.
     *
     * @return array{courses: list<array{id: string, code: string, title: string, department_id: string, department: string, credits: int, lecture_hours: int, tutorial_hours: int, practical_hours: int, needs: list<array{id: string, code: string}>, used_in: list<string>, retired: bool, locked: bool}>, departments: list<array{id: string, name: string, prefixes: list<string>}>}
     */
    public static function catalogue(string $divisionId): array
    {
        $rows = DB::table('programme_courses as c')->join('programme_departments as d', 'd.id', '=', 'c.department_id')->where('c.division_id', $divisionId)
            ->orderBy('c.code')->get(['c.*', 'd.name as department']);
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = Row::text($r, 'id');
        }
        $needs = self::needs($ids);

        $used = [];
        $locked = [];
        foreach (DB::table('programme_curriculum_courses as cc')->join('programme_curricula as cu', 'cu.id', '=', 'cc.curriculum_id')->join('programmes as p', 'p.id', '=', 'cu.programme_id')
            ->where('p.division_id', $divisionId)->get(['cc.course_id', 'p.code', 'cu.released_at']) as $u) {
            $used[Row::text($u, 'course_id')][Row::text($u, 'code')] = true;
            if (Row::maybeText($u, 'released_at') !== null) {
                $locked[Row::text($u, 'course_id')] = true;
            }
        }

        $courses = [];
        foreach ($rows as $c) {
            $id = Row::text($c, 'id');
            $courses[] = ['id' => $id, 'code' => Row::text($c, 'code'), 'title' => Row::text($c, 'title'), 'department_id' => Row::text($c, 'department_id'),
                'department' => Row::text($c, 'department'), 'credits' => Row::int($c, 'credits'), 'lecture_hours' => Row::int($c, 'lecture_hours'),
                'tutorial_hours' => Row::int($c, 'tutorial_hours'), 'practical_hours' => Row::int($c, 'practical_hours'), 'needs' => $needs[$id] ?? [],
                'used_in' => array_map('strval', array_keys($used[$id] ?? [])), 'retired' => Row::maybeText($c, 'retired_at') !== null, 'locked' => isset($locked[$id])];
        }

        $departments = [];
        foreach (DB::table('programme_departments as d')->join('programme_faculties as f', 'f.id', '=', 'd.faculty_id')->where('f.division_id', $divisionId)
            ->whereNull('d.retired_at')->orderBy('d.name')->get(['d.id', 'd.name', 'd.prefixes']) as $d) {
            $departments[] = ['id' => Row::text($d, 'id'), 'name' => Row::text($d, 'name'), 'prefixes' => self::prefixes($d)];
        }

        return ['courses' => $courses, 'departments' => $departments];
    }

    /**
     * A semester's offerings.
     *
     * @return list<array{id: string, course: array{id: string, code: string, title: string}, group: string, limit: int|null, lecturers: list<array{id: string, name: string}>, assistants: list<array{id: string, name: string}>, ready: bool}>
     */
    public static function offerings(string $divisionId, string $periodId): array
    {
        $rows = DB::table('programme_offerings as o')->join('programme_courses as c', 'c.id', '=', 'o.course_id')
            ->where('o.division_id', $divisionId)->where('o.period_id', $periodId)->orderBy('c.code')->orderBy('o.group_label')
            ->get(['o.id', 'o.group_label', 'o.size_limit', 'c.id as course_id', 'c.code', 'c.title']);

        $ids = [];
        foreach ($rows as $o) {
            $ids[] = Row::text($o, 'id');
        }
        $people = [];
        foreach (DB::table('programme_offering_staff as s')->join('users as u', 'u.id', '=', 's.user_id')->whereIn('s.offering_id', $ids)
            ->orderBy('u.name')->get(['s.offering_id', 's.role', 'u.id', 'u.name']) as $s) {
            $people[Row::text($s, 'offering_id')][Row::text($s, 'role')][] = ['id' => Row::text($s, 'id'), 'name' => Row::text($s, 'name')];
        }

        $out = [];
        foreach ($rows as $o) {
            $lecturers = $people[Row::text($o, 'id')]['lecturer'] ?? [];
            $out[] = ['id' => Row::text($o, 'id'), 'course' => ['id' => Row::text($o, 'course_id'), 'code' => Row::text($o, 'code'), 'title' => Row::text($o, 'title')],
                'group' => Row::text($o, 'group_label'), 'limit' => ($o->size_limit ?? null) !== null ? Row::int($o, 'size_limit') : null, 'lecturers' => $lecturers,
                'assistants' => $people[Row::text($o, 'id')]['assistant'] ?? [], 'ready' => $lecturers !== []];
        }

        return $out;
    }

    /**
     * The courses that released curricula place in this semester position and that have no offering yet.
     *
     * @return list<string>
     */
    public static function missingCourses(string $divisionId, string $periodId, int $position): array
    {
        $needed = DB::table('programme_curriculum_courses as cc')->join('programme_curricula as cu', 'cu.id', '=', 'cc.curriculum_id')
            ->join('programmes as p', 'p.id', '=', 'cu.programme_id')->join('programme_courses as c', 'c.id', '=', 'cc.course_id')
            ->where('p.division_id', $divisionId)->whereNull('p.retired_at')->whereNotNull('cu.released_at')->whereNull('c.retired_at')
            ->where('cc.semester', $position)->distinct()->get(['cc.course_id'])->map(fn (object $r): string => Row::text($r, 'course_id'))->all();
        $have = DB::table('programme_offerings')->where('period_id', $periodId)->distinct()->get(['course_id'])->map(fn (object $r): string => Row::text($r, 'course_id'))->all();

        $missing = array_values(array_diff($needed, $have));
        sort($missing);

        return $missing;
    }

    /**
     * Active people who hold a role now: those who may lecture.
     *
     * @return list<array{id: string, name: string}>
     */
    public static function staff(): array
    {
        $out = [];
        $holding = DB::table('access_role_assignments as a')->selectRaw('1')->whereColumn('a.user_id', 'u.id')->where('a.starts_at', '<=', now())
            ->where(fn (Builder $w) => $w->whereNull('a.ends_at')->orWhere('a.ends_at', '>', now()));
        foreach (DB::table('users as u')->where('u.status', 'active')->whereExists($holding)
            ->orderBy('u.name')->get(['u.id', 'u.name']) as $u) {
            $out[] = ['id' => Row::text($u, 'id'), 'name' => Row::text($u, 'name')];
        }

        return $out;
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array<string, int>
     */
    private static function counted(Collection $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[Row::text($r, 'department_id')] = Row::int($r, 'n');
        }

        return $out;
    }

    /** @return list<string> */
    private static function prefixes(object $row): array
    {
        $out = [];
        $list = json_decode(Row::text($row, 'prefixes'), true);
        foreach (is_array($list) ? $list : [] as $p) {
            if (is_string($p)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $courseIds
     * @return array<string, list<array{id: string, code: string}>>
     */
    private static function needs(array $courseIds): array
    {
        $out = [];
        foreach (DB::table('programme_course_needs as n')->join('programme_courses as c', 'c.id', '=', 'n.needs_course_id')->whereIn('n.course_id', $courseIds)
            ->orderBy('c.code')->get(['n.course_id', 'c.id', 'c.code']) as $n) {
            $out[Row::text($n, 'course_id')][] = ['id' => Row::text($n, 'id'), 'code' => Row::text($n, 'code')];
        }

        return $out;
    }
}
