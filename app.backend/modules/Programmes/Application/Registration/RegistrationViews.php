<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application\Registration;

use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Contracts\StudentStatus;
use Acadly\Modules\Programmes\Contracts\StudyMode;
use Acadly\Modules\Programmes\Domain\Registration;
use Acadly\Modules\Programmes\Journeys\Registration\State;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * What the registration screens read (D-098). Learner names and codes are
 * read with the learner rows, as the fee grids do; nothing here writes.
 *
 * @phpstan-import-type Clearance from FeesClearance
 */
final class RegistrationViews
{
    public function __construct(
        private readonly RegistrationChecks $checks,
        private readonly FeesClearance $fees,
        private readonly Windows $windows,
        private readonly JourneyEngine $engine,
    ) {}

    /**
     * The students in programmes now, with their registration for the semester.
     *
     * @return array{students: list<array{id: string, learner_id: string, code: string, name: string, programme: array{id: string, code: string}, curriculum: string, year: int, mode: string, status: string, registration: array{id: string, state: string}|null}>, candidates: list<array{id: string, code: string, name: string}>, programmes: list<array{id: string, code: string, name: string, years: int, curriculum: string|null}>}
     */
    public function students(string $divisionId, ?string $periodId): array
    {
        $registrations = [];
        if ($periodId !== null) {
            foreach (DB::table('programme_registrations')->where('period_id', $periodId)->get(['id', 'learner_id', 'state']) as $r) {
                $registrations[Row::text($r, 'learner_id')] = ['id' => Row::text($r, 'id'), 'state' => Row::text($r, 'state')];
            }
        }

        $students = [];
        foreach (DB::table('programme_students as s')->join('programmes as p', 'p.id', '=', 's.programme_id')->join('programme_curricula as cu', 'cu.id', '=', 's.curriculum_id')
            ->join('learners as l', 'l.id', '=', 's.learner_id')->where('s.division_id', $divisionId)->whereNull('s.ended_at')
            ->orderBy('l.surname')->orderBy('l.first_name')
            ->get(['s.id', 's.learner_id', 's.year_of_study', 's.mode', 's.status', 'p.id as programme_id', 'p.code', 'cu.label', 'l.acadly_id', 'l.first_name', 'l.surname']) as $s) {
            $students[] = ['id' => Row::text($s, 'id'), 'learner_id' => Row::text($s, 'learner_id'), 'code' => Row::text($s, 'acadly_id'),
                'name' => Row::text($s, 'first_name').' '.Row::text($s, 'surname'), 'programme' => ['id' => Row::text($s, 'programme_id'), 'code' => Row::text($s, 'code')],
                'curriculum' => Row::text($s, 'label'), 'year' => Row::int($s, 'year_of_study'), 'mode' => Row::text($s, 'mode'), 'status' => Row::text($s, 'status'),
                'registration' => $registrations[Row::text($s, 'learner_id')] ?? null];
        }

        // Learners placed in the division now, with no programme yet.
        $candidates = [];
        foreach (DB::table('placements as pl')->join('learners as l', 'l.id', '=', 'pl.learner_id')->where('pl.division_id', $divisionId)->whereNull('pl.ends_on')
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('programme_students as s')->whereColumn('s.learner_id', 'l.id')->whereNull('s.ended_at'))
            ->orderBy('l.surname')->orderBy('l.first_name')->limit(500)->get(['l.id', 'l.acadly_id', 'l.first_name', 'l.surname']) as $l) {
            $candidates[] = ['id' => Row::text($l, 'id'), 'code' => Row::text($l, 'acadly_id'), 'name' => Row::text($l, 'first_name').' '.Row::text($l, 'surname')];
        }

        $programmes = [];
        foreach (DB::table('programmes')->where('division_id', $divisionId)->whereNull('retired_at')->where('open_for_intake', true)->orderBy('code')->get(['id', 'code', 'name', 'years']) as $p) {
            $cu = DB::table('programme_curricula')->where('programme_id', Row::text($p, 'id'))->whereNotNull('released_at')->orderByDesc('released_at')->first(['label']);
            $programmes[] = ['id' => Row::text($p, 'id'), 'code' => Row::text($p, 'code'), 'name' => Row::text($p, 'name'), 'years' => Row::int($p, 'years'),
                'curriculum' => $cu !== null ? Row::text($cu, 'label') : null];
        }

        return ['students' => $students, 'candidates' => $candidates, 'programmes' => $programmes];
    }

    /**
     * The registration desk of a semester: its dates, every registration, and the students not started.
     *
     * @return array{window: array{opens_on: string, closes_on: string, late_until: string, add_drop_until: string, late_fee: int, fees_share: int, started_at: string|null}|null, phase: string, add_drop: bool, rows: list<array{id: string, learner_id: string, code: string, name: string, programme: string, year: int, courses: int, credits: int, state: string, late: bool, problems: int, fees_more: int}>, not_started: list<array{learner_id: string, code: string, name: string, programme: string, year: int}>, inactive: int}
     */
    public function desk(string $divisionId, string $periodId): array
    {
        $w = Windows::of($periodId);
        $rows = [];
        $credits = [];
        foreach (DB::table('programme_registration_courses as rc')->join('programme_registrations as r', function (JoinClause $j): void {
            $j->on('r.id', '=', 'rc.registration_id')->on('r.version', '=', 'rc.version');
        })->where('r.period_id', $periodId)->groupBy('rc.registration_id')->selectRaw('rc.registration_id, count(*) as n, sum(rc.credits) as c')->get() as $c) {
            $credits[Row::text($c, 'registration_id')] = [Row::int($c, 'n'), Row::int($c, 'c')];
        }

        $registrations = Registration::query()->where('division_id', $divisionId)->where('period_id', $periodId)->get();
        $people = [];
        foreach (DB::table('learners as l')->join('programme_students as s', 's.learner_id', '=', 'l.id')->join('programmes as p', 'p.id', '=', 's.programme_id')
            ->whereIn('s.id', $registrations->pluck('student_id')->all())->get(['s.id', 'l.acadly_id', 'l.first_name', 'l.surname', 'p.code']) as $p) {
            $people[Row::text($p, 'id')] = ['code' => Row::text($p, 'acadly_id'), 'name' => Row::text($p, 'first_name').' '.Row::text($p, 'surname'), 'programme' => Row::text($p, 'code')];
        }

        foreach ($registrations as $r) {
            $who = $people[$r->student_id] ?? ['code' => '', 'name' => '', 'programme' => ''];
            $problems = in_array($r->state, [State::Draft->value, State::Submitted->value], true) ? count($this->checks->run($r)['problems']) : 0;
            $more = $r->state === State::Approved->value ? $this->fees->of($r)['more'] : 0;
            $rows[] = ['id' => $r->id, 'learner_id' => $r->learner_id, 'code' => $who['code'], 'name' => $who['name'], 'programme' => $who['programme'], 'year' => $r->year_of_study,
                'courses' => $credits[$r->id][0] ?? 0, 'credits' => $credits[$r->id][1] ?? 0, 'state' => $r->state, 'late' => $r->late, 'problems' => $problems, 'fees_more' => $more];
        }
        usort($rows, fn (array $a, array $b): int => [$a['name'], $a['code']] <=> [$b['name'], $b['code']]);

        $notStarted = [];
        foreach (DB::table('programme_students as s')->join('learners as l', 'l.id', '=', 's.learner_id')->join('programmes as p', 'p.id', '=', 's.programme_id')
            ->where('s.division_id', $divisionId)->whereNull('s.ended_at')->where('s.status', StudentStatus::Active->value)
            ->whereNotIn('s.learner_id', $registrations->pluck('learner_id')->all())->orderBy('l.surname')->orderBy('l.first_name')
            ->get(['s.learner_id', 's.year_of_study', 'l.acadly_id', 'l.first_name', 'l.surname', 'p.code']) as $s) {
            $notStarted[] = ['learner_id' => Row::text($s, 'learner_id'), 'code' => Row::text($s, 'acadly_id'), 'name' => Row::text($s, 'first_name').' '.Row::text($s, 'surname'),
                'programme' => Row::text($s, 'code'), 'year' => Row::int($s, 'year_of_study')];
        }

        $inactive = DB::table('programme_students')->where('division_id', $divisionId)->whereNull('ended_at')->where('status', '<>', StudentStatus::Active->value)->count();

        return ['window' => $w === null ? null : ['opens_on' => $w['opens_on'], 'closes_on' => $w['closes_on'], 'late_until' => $w['late_until'], 'add_drop_until' => $w['add_drop_until'],
            'late_fee' => $w['late_fee'], 'fees_share' => $w['fees_share'], 'started_at' => $w['started_at']],
            'phase' => $this->windows->phase($periodId), 'add_drop' => $this->windows->addDropOpen($periodId), 'rows' => $rows, 'not_started' => $notStarted, 'inactive' => $inactive];
    }

    /**
     * A student's programme, its history and every semester registration, newest first; null when not in a programme.
     *
     * @return array{division_id: string, programme: array{code: string, name: string}, curriculum: string, year: int, mode: string, status: string, history: list<array{year: int, status: string, reason: string|null, at: string}>, registrations: list<array{id: string, period: string, state: string, year: int, semester: int, version: int, late: bool, courses: list<array{code: string, title: string, kind: string, credits: int}>}>}|null
     */
    public function learner(string $learnerId): ?array
    {
        $rows = DB::table('programme_students as s')->join('programmes as p', 'p.id', '=', 's.programme_id')->join('programme_curricula as cu', 'cu.id', '=', 's.curriculum_id')
            ->where('s.learner_id', $learnerId)->orderByDesc('s.created_at')->get(['s.division_id', 's.year_of_study', 's.mode', 's.status', 's.reason', 's.created_at', 's.ended_at', 'p.code', 'p.name', 'cu.label']);
        $now = $rows->first(fn (object $s): bool => ($s->ended_at ?? null) === null);

        if ($now === null) {
            return null;
        }

        $registrations = [];
        foreach (Registration::query()->join('academic_periods as ap', 'ap.id', '=', 'programme_registrations.period_id')->where('programme_registrations.learner_id', $learnerId)
            ->orderByDesc('ap.starts_on')->get(['programme_registrations.*', 'ap.name as period_name']) as $r) {
            $courses = [];
            foreach (DB::table('programme_registration_courses as rc')->join('programme_courses as c', 'c.id', '=', 'rc.course_id')->where('rc.registration_id', $r->id)
                ->where('rc.version', $r->version)->orderBy('c.code')->get(['c.code', 'c.title', 'rc.kind', 'rc.credits']) as $c) {
                $courses[] = ['code' => Row::text($c, 'code'), 'title' => Row::text($c, 'title'), 'kind' => Row::text($c, 'kind'), 'credits' => Row::int($c, 'credits')];
            }
            $name = $r->getAttribute('period_name');
            $registrations[] = ['id' => $r->id, 'period' => is_string($name) ? $name : '', 'state' => $r->state, 'year' => $r->year_of_study, 'semester' => $r->semester,
                'version' => $r->version, 'late' => $r->late, 'courses' => $courses];
        }

        $history = [];
        foreach ($rows as $s) {
            $history[] = ['year' => Row::int($s, 'year_of_study'), 'status' => Row::text($s, 'status'), 'reason' => Row::maybeText($s, 'reason'), 'at' => Row::text($s, 'created_at')];
        }

        return ['division_id' => Row::text($now, 'division_id'), 'programme' => ['code' => Row::text($now, 'code'), 'name' => Row::text($now, 'name')], 'curriculum' => Row::text($now, 'label'),
            'year' => Row::int($now, 'year_of_study'), 'mode' => Row::text($now, 'mode'), 'status' => Row::text($now, 'status'), 'history' => $history, 'registrations' => $registrations];
    }

    /**
     * One registration, with what can be chosen and what can be done now.
     *
     * @return array{id: string, state: string, late: bool, version: int, period: string, period_id: string, student: array{learner_id: string, code: string, name: string, programme: array{id: string, code: string, name: string}, curriculum: string, year: int, semester: int, mode: string}, courses: list<array{id: string, offering_id: string, course: array{id: string, code: string, title: string}, kind: string, elective_group_id: string|null, group: string, credits: int, needs: list<string>}>, core: list<string>, groups: list<array{id: string, label: string, choose: int, courses: list<string>}>, offerings: list<array{id: string, course: array{id: string, code: string, title: string}, group: string, credits: int, seats_left: int|null}>, checks: array{problems: list<array{kind: string, text: string}>, load: int, min: int, max: int}, fees: Clearance, steps: list<array{step: string, title: string, outcomes: list<array{outcome: string, title: string, requires_reason: bool}>, permitted: bool, blocked_reason: string|null, remedy: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, phase: string, add_drop: bool}
     */
    public function registration(User $viewer, Registration $r): array
    {
        $s = DB::table('programme_students as s')->join('programmes as p', 'p.id', '=', 's.programme_id')->join('programme_curricula as cu', 'cu.id', '=', 's.curriculum_id')
            ->join('learners as l', 'l.id', '=', 's.learner_id')->where('s.id', $r->student_id)
            ->first(['s.curriculum_id', 's.mode', 'p.id as programme_id', 'p.code', 'p.name', 'cu.label', 'l.acadly_id', 'l.first_name', 'l.surname']);
        $period = DB::table('academic_periods')->where('id', $r->period_id)->first(['name']);
        $curriculum = $s !== null ? Row::text($s, 'curriculum_id') : '';
        $spot = RegistrationChecks::spot($curriculum, $r->year_of_study, $r->semester);

        $rows = RegistrationChecks::courses($r);
        $titles = [];
        foreach (DB::table('programme_courses')->whereIn('id', array_column($rows, 'course_id'))->get(['id', 'code', 'title']) as $c) {
            $titles[Row::text($c, 'id')] = ['id' => Row::text($c, 'id'), 'code' => Row::text($c, 'code'), 'title' => Row::text($c, 'title')];
        }
        $needs = [];
        foreach (DB::table('programme_course_needs as n')->join('programme_courses as c', 'c.id', '=', 'n.needs_course_id')->whereIn('n.course_id', array_column($rows, 'course_id'))
            ->orderBy('c.code')->get(['n.course_id', 'c.code']) as $n) {
            $needs[Row::text($n, 'course_id')][] = Row::text($n, 'code');
        }
        $courses = array_map(fn (array $c): array => ['id' => $c['id'], 'offering_id' => $c['offering_id'], 'course' => $titles[$c['course_id']] ?? ['id' => $c['course_id'], 'code' => '', 'title' => ''],
            'kind' => $c['kind'], 'elective_group_id' => $c['elective_group_id'], 'group' => $c['group'], 'credits' => $c['credits'], 'needs' => $needs[$c['course_id']] ?? []], $rows);

        $offered = DB::table('programme_offerings as o')->join('programme_courses as c', 'c.id', '=', 'o.course_id')->where('o.period_id', $r->period_id)
            ->orderBy('c.code')->orderBy('o.group_label')->get(['o.id', 'o.group_label', 'o.size_limit', 'c.id as course_id', 'c.code', 'c.title', 'c.credits']);
        $taken = RegistrationChecks::taken(array_values(array_map(fn (object $o): string => Row::text($o, 'id'), $offered->all())), $r->id);
        $offerings = [];
        foreach ($offered as $o) {
            $limit = ($o->size_limit ?? null) !== null ? Row::int($o, 'size_limit') : null;
            $offerings[] = ['id' => Row::text($o, 'id'), 'course' => ['id' => Row::text($o, 'course_id'), 'code' => Row::text($o, 'code'), 'title' => Row::text($o, 'title')],
                'group' => Row::text($o, 'group_label'), 'credits' => Row::int($o, 'credits'), 'seats_left' => $limit !== null ? max(0, $limit - ($taken[Row::text($o, 'id')] ?? 0)) : null];
        }

        $history = [];
        $hist = DB::table('journey_step_completions as j')->leftJoin('users as u', 'u.id', '=', 'j.actor_user_id')->where('j.subject_type', $r->auditType())->where('j.subject_id', $r->id)
            ->orderByDesc('j.seq')->get(['j.step', 'j.outcome', 'j.occurred_at', 'j.reason', 'u.name']);
        foreach ($hist as $h) {
            $history[] = ['step' => Row::text($h, 'step'), 'outcome' => Row::text($h, 'outcome'), 'by' => Row::maybeText($h, 'name') ?? '', 'at' => Row::text($h, 'occurred_at'), 'reason' => Row::maybeText($h, 'reason')];
        }

        return [
            'id' => $r->id, 'state' => $r->state, 'late' => $r->late, 'version' => $r->version, 'period' => $period !== null ? Row::text($period, 'name') : '', 'period_id' => $r->period_id,
            'student' => ['learner_id' => $r->learner_id, 'code' => $s !== null ? Row::text($s, 'acadly_id') : '', 'name' => $s !== null ? Row::text($s, 'first_name').' '.Row::text($s, 'surname') : '',
                'programme' => ['id' => $s !== null ? Row::text($s, 'programme_id') : '', 'code' => $s !== null ? Row::text($s, 'code') : '', 'name' => $s !== null ? Row::text($s, 'name') : ''],
                'curriculum' => $s !== null ? Row::text($s, 'label') : '', 'year' => $r->year_of_study, 'semester' => $r->semester, 'mode' => $s !== null ? Row::text($s, 'mode') : StudyMode::FullTime->value],
            'courses' => $courses, 'core' => $spot['core'], 'groups' => $spot['groups'], 'offerings' => $offerings,
            'checks' => $this->checks->run($r), 'fees' => $this->fees->of($r),
            'steps' => array_map(fn ($a): array => $a->toArray(), $this->engine->available($r, $viewer)),
            'history' => $history, 'phase' => $this->windows->phase($r->period_id), 'add_drop' => $this->windows->addDropOpen($r->period_id),
        ];
    }
}
