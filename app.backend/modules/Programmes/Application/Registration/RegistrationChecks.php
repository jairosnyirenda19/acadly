<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application\Registration;

use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Application\StudentProgressWriter;
use Acadly\Modules\Programmes\Contracts\StudyMode;
use Acadly\Modules\Programmes\Domain\Registration;
use Acadly\Modules\Programmes\Journeys\Registration\State;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The checks of a semester registration (D-098), the same on the screen and
 * at the gate: every core course of the semester is chosen (full time),
 * each elective group has its choice, the load is in the programme's range,
 * every course needed first was taken earlier or credited on entry, and
 * every group chosen has a seat.
 *
 * @phpstan-type Spot array{core: list<string>, groups: list<array{id: string, label: string, choose: int, courses: list<string>}>}
 * @phpstan-type Problem array{kind: string, text: string}
 */
final class RegistrationChecks
{
    /** States whose courses hold a seat. */
    public const array HOLDING = [State::Submitted->value, State::Approved->value, State::Registered->value];

    /** @return array{problems: list<Problem>, load: int, min: int, max: int} */
    public function run(Registration $r): array
    {
        $student = DB::table('programme_students as s')->join('programmes as p', 'p.id', '=', 's.programme_id')->where('s.id', $r->student_id)
            ->first(['s.curriculum_id', 's.mode', 's.entry_year', 's.entry_semester', 'p.min_load', 'p.max_load']);

        if ($student === null) {
            return ['problems' => [['kind' => 'none', 'text' => 'The student is not in a programme.']], 'load' => 0, 'min' => 0, 'max' => 0];
        }

        $rows = self::courses($r);
        $spot = self::spot(Row::text($student, 'curriculum_id'), $r->year_of_study, $r->semester);
        $chosen = array_column($rows, 'course_id');
        $codes = self::codes([...$chosen, ...$spot['core']]);
        $load = array_sum(array_column($rows, 'credits'));
        $min = Row::int($student, 'min_load');
        $max = Row::int($student, 'max_load');
        $fullTime = Row::text($student, 'mode') === StudyMode::FullTime->value;
        $problems = [];

        if ($rows === []) {
            $problems[] = ['kind' => 'none', 'text' => 'No course is chosen.'];
        }

        if ($fullTime) {
            foreach ($spot['core'] as $core) {
                if (! in_array($core, $chosen, true)) {
                    $problems[] = ['kind' => 'core', 'text' => ($codes[$core] ?? '').' is a core course of this semester and is not chosen.'];
                }
            }
        }

        // D-106: a carried course offered this semester must be taken.
        foreach (StudentProgressWriter::carried($r->learner_id) as $carried) {
            if (! in_array($carried, $chosen, true) && DB::table('programme_offerings')->where('period_id', $r->period_id)->where('course_id', $carried)->exists()) {
                $code = DB::table('programme_courses')->where('id', $carried)->value('code');
                $problems[] = ['kind' => 'carried', 'text' => (is_string($code) ? $code : '').' is carried from an earlier year and must be taken.'];
            }
        }

        foreach ($spot['groups'] as $g) {
            $n = count(array_intersect($g['courses'], $chosen));
            if ($n !== $g['choose']) {
                $problems[] = ['kind' => 'group', 'text' => "{$g['label']}: choose {$g['choose']}; {$n} chosen."];
            }
        }

        if ($rows !== [] && $fullTime && $load < $min) {
            $problems[] = ['kind' => 'load', 'text' => "The load is {$load} credits; the lowest is {$min}."];
        }

        if ($load > $max) {
            $problems[] = ['kind' => 'load', 'text' => "The load is {$load} credits; the highest is {$max}."];
        }

        $met = self::metEarlier($r, Row::text($student, 'curriculum_id'), Row::int($student, 'entry_year'), Row::int($student, 'entry_semester'));
        foreach (DB::table('programme_course_needs as n')->join('programme_courses as c', 'c.id', '=', 'n.needs_course_id')->whereIn('n.course_id', $chosen)
            ->orderBy('c.code')->get(['n.course_id', 'n.needs_course_id', 'c.code']) as $n) {
            if (! in_array(Row::text($n, 'needs_course_id'), $met, true)) {
                $problems[] = ['kind' => 'needs', 'text' => ($codes[Row::text($n, 'course_id')] ?? '').' needs '.Row::text($n, 'code').' first.'];
            }
        }

        $taken = self::taken(array_column($rows, 'offering_id'), $r->id);
        foreach ($rows as $row) {
            if ($row['limit'] !== null && ($taken[$row['offering_id']] ?? 0) >= $row['limit']) {
                $problems[] = ['kind' => 'seats', 'text' => ($codes[$row['course_id']] ?? '').' group '.$row['group'].' is full.'];
            }
        }

        return ['problems' => $problems, 'load' => $load, 'min' => $fullTime ? $min : 0, 'max' => $max];
    }

    /**
     * The courses of the registration's current version.
     *
     * @return list<array{id: string, offering_id: string, course_id: string, kind: string, elective_group_id: string|null, credits: int, group: string, limit: int|null}>
     */
    public static function courses(Registration $r): array
    {
        $out = [];
        foreach (DB::table('programme_registration_courses as rc')->join('programme_offerings as o', 'o.id', '=', 'rc.offering_id')->join('programme_courses as c', 'c.id', '=', 'rc.course_id')
            ->where('rc.registration_id', $r->id)->where('rc.version', $r->version)->orderBy('c.code')
            ->get(['rc.id', 'rc.offering_id', 'rc.course_id', 'rc.kind', 'rc.elective_group_id', 'rc.credits', 'o.group_label', 'o.size_limit']) as $row) {
            $out[] = ['id' => Row::text($row, 'id'), 'offering_id' => Row::text($row, 'offering_id'), 'course_id' => Row::text($row, 'course_id'), 'kind' => Row::text($row, 'kind'),
                'elective_group_id' => Row::maybeText($row, 'elective_group_id'), 'credits' => Row::int($row, 'credits'), 'group' => Row::text($row, 'group_label'),
                'limit' => ($row->size_limit ?? null) !== null ? Row::int($row, 'size_limit') : null];
        }

        return $out;
    }

    /**
     * What the curriculum places at one year and semester.
     *
     * @return Spot
     */
    public static function spot(string $curriculumId, int $year, int $semester): array
    {
        $core = [];
        $members = [];
        foreach (DB::table('programme_curriculum_courses')->where('curriculum_id', $curriculumId)->where('year', $year)->where('semester', $semester)
            ->orderBy('position')->get(['course_id', 'group_id']) as $c) {
            $group = Row::maybeText($c, 'group_id');
            if ($group === null) {
                $core[] = Row::text($c, 'course_id');
            } else {
                $members[$group][] = Row::text($c, 'course_id');
            }
        }

        $groups = [];
        foreach (DB::table('programme_elective_groups')->where('curriculum_id', $curriculumId)->where('year', $year)->where('semester', $semester)
            ->orderBy('position')->get(['id', 'label', 'choose']) as $g) {
            $groups[] = ['id' => Row::text($g, 'id'), 'label' => Row::text($g, 'label'), 'choose' => Row::int($g, 'choose'), 'courses' => $members[Row::text($g, 'id')] ?? []];
        }

        return ['core' => $core, 'groups' => $groups];
    }

    /**
     * Seats held in each offering by registrations past the draft, except one.
     *
     * @param  list<string>  $offeringIds
     * @return array<string, int>
     */
    public static function taken(array $offeringIds, ?string $except = null): array
    {
        $out = [];
        if ($offeringIds === []) {
            return $out;
        }

        $q = DB::table('programme_registration_courses as rc')->join('programme_registrations as r', function (JoinClause $j): void {
            $j->on('r.id', '=', 'rc.registration_id')->on('r.version', '=', 'rc.version');
        })->whereIn('rc.offering_id', $offeringIds)->whereIn('r.state', self::HOLDING);

        if ($except !== null) {
            $q->where('r.id', '<>', $except);
        }

        foreach ($q->groupBy('rc.offering_id')->selectRaw('rc.offering_id, count(*) as n')->get() as $row) {
            $out[Row::text($row, 'offering_id')] = Row::int($row, 'n');
        }

        return $out;
    }

    /**
     * @param  list<string>  $courseIds
     * @return array<string, string> id => code
     */
    public static function codes(array $courseIds): array
    {
        $out = [];
        foreach (DB::table('programme_courses')->whereIn('id', array_values(array_unique($courseIds)))->get(['id', 'code']) as $c) {
            $out[Row::text($c, 'id')] = Row::text($c, 'code');
        }

        return $out;
    }

    /**
     * The courses that count as taken before this semester: registered in an
     * earlier semester, or placed by the curriculum before the student's entry.
     *
     * @return list<string>
     */
    private static function metEarlier(Registration $r, string $curriculumId, int $entryYear, int $entrySemester): array
    {
        $met = [];
        foreach (DB::table('programme_registration_courses as rc')->join('programme_registrations as r', function (JoinClause $j): void {
            $j->on('r.id', '=', 'rc.registration_id')->on('r.version', '=', 'rc.version');
        })->where('r.learner_id', $r->learner_id)->where('r.id', '<>', $r->id)->where('r.state', State::Registered->value)
            ->where(fn (Builder $w) => $w->where('r.year_of_study', '<', $r->year_of_study)->orWhere(fn (Builder $x) => $x->where('r.year_of_study', $r->year_of_study)->where('r.semester', '<', $r->semester)))
            ->get(['rc.course_id']) as $row) {
            $met[] = Row::text($row, 'course_id');
        }

        foreach (DB::table('programme_curriculum_courses')->where('curriculum_id', $curriculumId)
            ->where(fn (Builder $w) => $w->where('year', '<', $entryYear)->orWhere(fn (Builder $x) => $x->where('year', $entryYear)->where('semester', '<', $entrySemester)))
            ->get(['course_id']) as $row) {
            $met[] = Row::text($row, 'course_id');
        }

        return array_values(array_unique($met));
    }
}
