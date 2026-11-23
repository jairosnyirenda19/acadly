<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Classroom\Contracts\ContinuousAssessment;
use Acadly\Modules\Lectures\Contracts\LectureAttendance;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Acadly\Modules\Programmes\Contracts\ProgrammesSlot;
use Acadly\Modules\Programmes\Contracts\StudyShape;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Acadly\Modules\Results\Domain\Sheet;
use Acadly\Modules\Results\Journeys\Sheet\State;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The courses Results works on and how each student's result is worked out
 * (D-105). Course mark = continuous assessment (out of the course's share,
 * from Classroom) + the exam mark scaled to the rest, rounded to a whole
 * mark. A student below the attendance bar sits no exam: the course counts
 * as failed and goes to the supplementary sitting, unless the bar is lifted.
 * An absence the board accepts defers the exam to the supplementary sitting,
 * as a first attempt. A supplementary pass is recorded at the pass mark.
 *
 * @phpstan-import-type Offering from CourseTeaching
 * @phpstan-import-type Scale from Scales
 *
 * @phpstan-type Period array{id: string, name: string, starts_on: string, ends_on: string}
 * @phpstan-type Course array{offering: Offering, period: Period, division_id: string}
 * @phpstan-type Line array{learner_id: string, name: string, number: string, ca: float, share: int, held: int, attended: int, rate: int|null, barred: bool, lifted: string|null, exam: float|null, absence: string|null, mark: int|null, grade: string|null, points: float|null, result: string, kind: string, changed: bool}
 */
final class Sheets
{
    public function __construct(
        private readonly CourseTeaching $teaching,
        private readonly AcademicCalendar $calendar,
        private readonly SchoolDirectory $school,
        private readonly Access $access,
        private readonly ModuleActivation $modules,
        private readonly Container $container,
        private readonly Clock $clock,
    ) {}

    /** @return list<Period> */
    public function periods(string $divisionId): array
    {
        return $this->calendar->currentYear($this->clock->today())['terms'][$divisionId] ?? [];
    }

    /** @return Period|null */
    public function current(string $divisionId): ?array
    {
        $today = $this->clock->today();
        $periods = $this->periods($divisionId);
        foreach ($periods as $p) {
            if ($p['ends_on'] >= $today) {
                return $p;
            }
        }

        return $periods === [] ? null : $periods[count($periods) - 1];
    }

    /** @return Period */
    public function period(string $divisionId, ?string $periodId): array
    {
        foreach ($this->periods($divisionId) as $p) {
            if ($p['id'] === $periodId) {
                return $p;
            }
        }
        if ($periodId === null && ($c = $this->current($divisionId)) !== null) {
            return $c;
        }
        throw new NotFoundHttpException;
    }

    /** @return list<Offering> */
    public function offerings(string $divisionId, string $periodId): array
    {
        return $this->teaching->offerings($divisionId, $periodId);
    }

    /** @return Course */
    public function find(string $divisionId, string $offeringId): array
    {
        foreach ($this->periods($divisionId) as $p) {
            foreach ($this->teaching->offerings($divisionId, $p['id']) as $o) {
                if ($o['id'] === $offeringId) {
                    return ['offering' => $o, 'period' => $p, 'division_id' => $divisionId];
                }
            }
        }
        throw new NotFoundHttpException;
    }

    /** Checks the division studies by programmes and the person holds a Results permission there. */
    public function enter(User $who, string $divisionId): void
    {
        $at = ScopeRef::of(ScopeType::Division, $divisionId);
        $any = false;
        foreach (ResultsPermission::cases() as $p) {
            $any = $any || $this->access->allows($who, $p, $at);
        }
        if (! $any) {
            $this->access->check($who, ResultsPermission::View, $at);
        }
        $division = $this->school->division($divisionId);
        if ($division === null || $division->profile->binding(ProgrammesSlot::Shape) !== StudyShape::Programmes) {
            throw new NotFoundHttpException;
        }
    }

    public function holds(User $who, ResultsPermission $p, string $divisionId): bool
    {
        return $this->access->allows($who, $p, ScopeRef::of(ScopeType::Division, $divisionId));
    }

    /** Whether the person sees every sheet of the division. */
    public function sees(User $who, string $divisionId): bool
    {
        foreach ([ResultsPermission::View, ResultsPermission::Moderate, ResultsPermission::Board, ResultsPermission::Release] as $p) {
            if ($this->holds($who, $p, $divisionId)) {
                return true;
            }
        }

        return false;
    }

    /** @param  Course  $course */
    public function teaches(User $who, array $course): bool
    {
        return $this->holds($who, ResultsPermission::Enter, $course['division_id']) && in_array($who->id, array_column($course['offering']['lecturers'], 'id'), true);
    }

    /** @return Course */
    public function open(User $who, string $divisionId, string $offeringId): array
    {
        $this->enter($who, $divisionId);
        $course = $this->find($divisionId, $offeringId);
        if (! $this->teaches($who, $course) && ! $this->sees($who, $divisionId)) {
            throw new NotFoundHttpException;
        }

        return $course;
    }

    /** @param  Course  $course */
    public function must(User $who, array $course, ResultsPermission $p): void
    {
        if (! $this->holds($who, $p, $course['division_id'])) {
            throw new AccessDeniedHttpException;
        }
    }

    public function sheet(string $offeringId, string $sitting): ?Sheet
    {
        return Sheet::query()->where('offering_id', $offeringId)->where('sitting', $sitting)->first();
    }

    /** @return Scale */
    public function scale(string $divisionId, string $periodId): array
    {
        foreach ($this->periods($divisionId) as $p) {
            if ($p['id'] === $periodId) {
                return Scales::for($divisionId, $p['starts_on']);
            }
        }
        $starts = DB::table('academic_periods')->where('id', $periodId)->value('starts_on');

        return Scales::for($divisionId, is_string($starts) ? $starts : $this->clock->today());
    }

    /**
     * Continuous assessment now, from Classroom; with Classroom off the exam is the whole mark.
     *
     * @return array{share: int, totals: array<string, float>, unreleased: int}
     */
    public function assessment(string $offeringId, string $divisionId): array
    {
        $at = ScopeRef::of(ScopeType::Division, $divisionId);
        if (! $this->modules->isActive('classroom', $at) || ! $this->container->bound(ContinuousAssessment::class)) {
            return ['share' => 0, 'totals' => [], 'unreleased' => 0];
        }

        return $this->container->make(ContinuousAssessment::class)->of($offeringId);
    }

    /**
     * Lectures attended now; with Lectures off there is no bar.
     *
     * @return array<string, array{held: int, attended: int}>|null
     */
    public function attendance(string $offeringId, string $divisionId): ?array
    {
        $at = ScopeRef::of(ScopeType::Division, $divisionId);
        if (! $this->modules->isActive('lectures', $at) || ! $this->container->bound(LectureAttendance::class)) {
            return null;
        }

        return $this->container->make(LectureAttendance::class)->rates($offeringId);
    }

    /**
     * The students of a sheet: everyone holding a place, or for the supplementary sitting, those sent to it.
     *
     * @param  Course  $course
     * @return list<array{learner_id: string, name: string, number: string, user_id: string|null, phone: string|null}>
     */
    public function students(array $course, string $sitting): array
    {
        $all = $this->teaching->students($course['offering']['id']);
        if ($sitting === 'main') {
            return $all;
        }
        $main = $this->sheet($course['offering']['id'], 'main');
        if ($main === null || $main->state !== State::Released->value) {
            return [];
        }
        $sent = [];
        foreach ($this->lines($main, $course) as $l) {
            if (in_array($l['result'], ['fail', 'barred', 'deferred'], true)) {
                $sent[$l['learner_id']] = $l['result'];
            }
        }

        return array_values(array_filter($all, fn (array $s): bool => isset($sent[$s['learner_id']])));
    }

    /**
     * Every student's line on a sheet (or on the main sitting before a sheet exists).
     *
     * @param  Course  $course
     * @return list<Line>
     */
    public function lines(?Sheet $sheet, array $course, ?string $only = null): array
    {
        $sitting = $sheet->sitting ?? 'main';
        $offeringId = $course['offering']['id'];
        $scale = $this->scale($course['division_id'], $course['period']['id']);
        $settings = Scales::settings($course['division_id']);
        $frozen = [];
        if ($sheet !== null && $sheet->state !== State::Entered->value) {
            foreach (DB::table('results_lines')->where('sheet_id', $sheet->id)->orderBy('created_at')->get() as $f) {
                $frozen[Row::text($f, 'learner_id')] = $f;
            }
        }
        $live = $frozen === [] ? $this->assessment($offeringId, $course['division_id']) : null;
        $rates = $frozen === [] ? $this->attendance($offeringId, $course['division_id']) : null;
        $lifts = DB::table('results_bar_lifts')->where('offering_id', $offeringId)->pluck('reason', 'learner_id')->all();
        $marks = $sheet === null ? [] : self::marks($sheet->id);
        $mainLines = [];
        if ($sitting === 'supplementary' && ($main = $this->sheet($offeringId, 'main')) !== null) {
            foreach ($this->lines($main, $course) as $l) {
                $mainLines[$l['learner_id']] = $l;
            }
        }

        $out = [];
        foreach ($this->students($course, $sitting) as $s) {
            $id = $s['learner_id'];
            if ($only !== null && $id !== $only) {
                continue;
            }
            $f = $frozen[$id] ?? null;
            if ($f !== null) {
                $ca = (float) Row::text($f, 'ca');
                $share = Row::int($f, 'ca_share');
                $held = Row::int($f, 'held');
                $attended = Row::int($f, 'attended');
                $barred = (bool) $f->barred;
            } else {
                $ca = (float) ($live['totals'][$id] ?? 0.0);
                $share = $live['share'] ?? 0;
                $held = $rates[$id]['held'] ?? 0;
                $attended = $rates[$id]['attended'] ?? 0;
                $barred = $sitting === 'main' && $held > 0 && $attended * 100 < $settings['bar_percent'] * $held;
            }
            $lifted = isset($lifts[$id]) && is_string($lifts[$id]) ? $lifts[$id] : null;
            if ($lifted !== null) {
                $barred = false;
            }
            $m = $marks[$id] ?? null;
            $kind = $sitting === 'main' ? 'first' : (($mainLines[$id]['result'] ?? '') === 'deferred' ? 'deferred' : 'supplementary');
            $line = ['learner_id' => $id, 'name' => $s['name'], 'number' => $s['number'], 'ca' => round($ca, 1), 'share' => $share, 'held' => $held, 'attended' => $attended,
                'rate' => $held > 0 ? (int) round($attended * 100 / $held) : null, 'barred' => $barred, 'lifted' => $lifted,
                'exam' => $m['exam'] ?? null, 'absence' => $m['absence'] ?? null, 'mark' => null, 'grade' => null, 'points' => null, 'result' => 'missing', 'kind' => $kind,
                'changed' => ($m['stage'] ?? 'entry') !== 'entry'];
            $out[] = self::work($line, $scale);
        }

        return $out;
    }

    /**
     * Works out one line's mark, grade and result.
     *
     * @param  Line  $l
     * @param  Scale  $scale
     * @return Line
     */
    public static function work(array $l, array $scale): array
    {
        $fail = Scales::grade($scale, 0);
        if ($l['barred']) {
            return [...$l, 'grade' => $fail['grade'], 'points' => $fail['points'], 'result' => 'barred'];
        }
        if ($l['absence'] === 'deferred') {
            return [...$l, 'result' => 'deferred'];
        }
        if ($l['exam'] === null && $l['absence'] === null) {
            return [...$l, 'result' => 'missing'];
        }
        $exam = $l['absence'] === 'absent' ? 0.0 : (float) $l['exam'];
        $mark = min(100, Scales::whole($l['ca'] + $exam * (100 - $l['share']) / 100));
        if ($l['kind'] === 'supplementary') {
            $passed = $exam >= $scale['pass_mark'];
            $mark = $passed ? $scale['pass_mark'] : min($mark, $scale['pass_mark'] - 1);
            $g = Scales::grade($scale, $mark);

            return [...$l, 'mark' => $mark, 'grade' => $g['grade'], 'points' => $g['points'], 'result' => $passed ? 'pass' : 'repeat'];
        }
        $g = Scales::grade($scale, $mark);

        return [...$l, 'mark' => $mark, 'grade' => $g['grade'], 'points' => $g['points'], 'result' => $mark >= $scale['pass_mark'] ? 'pass' : 'fail'];
    }

    /** How many students may sit and have neither a mark nor a recorded absence. */
    public function missing(Sheet $sheet): int
    {
        $course = $this->find($sheet->division_id, $sheet->offering_id);

        return count(array_filter($this->lines($sheet, $course), fn (array $l): bool => $l['result'] === 'missing'));
    }

    /**
     * The latest exam mark of each student on a sheet.
     *
     * @return array<string, array{exam: float|null, absence: string|null, stage: string, reason: string|null, by: string, at: string, was: float|null}>
     */
    public static function marks(string $sheetId): array
    {
        $out = [];
        foreach (DB::table('results_marks as m')->join('users as u', 'u.id', '=', 'm.by_user_id')->where('m.sheet_id', $sheetId)->orderBy('m.created_at')->orderBy('m.id')
            ->get(['m.learner_id', 'm.exam', 'm.absence', 'm.stage', 'm.reason', 'm.created_at', 'u.name']) as $m) {
            $id = Row::text($m, 'learner_id');
            $out[$id] = ['exam' => is_numeric($m->exam ?? null) ? (float) $m->exam : null, 'absence' => Row::maybeText($m, 'absence'), 'stage' => Row::text($m, 'stage'),
                'reason' => Row::maybeText($m, 'reason'), 'by' => Row::text($m, 'name'), 'at' => Row::text($m, 'created_at'), 'was' => $out[$id]['exam'] ?? null];
        }

        return $out;
    }

    /**
     * A student's result in a course: the supplementary sitting's once released, else the main one's.
     *
     * @param  Course  $course
     * @return Line|null
     */
    public function finalLine(array $course, string $learnerId, bool $releasedOnly): ?array
    {
        $found = null;
        foreach (['main', 'supplementary'] as $sitting) {
            $sheet = $this->sheet($course['offering']['id'], $sitting);
            if ($sheet === null || ($releasedOnly && $sheet->state !== State::Released->value)) {
                continue;
            }
            $line = $this->lines($sheet, $course, $learnerId)[0] ?? null;
            // A supplementary paper not yet marked leaves the main sitting's result in force.
            if ($line !== null && ($found === null || $line['result'] !== 'missing')) {
                $found = $line;
            }
        }

        return $found;
    }

    /**
     * The grade spread of a set of lines, from the top band down, with the average mark and pass rate.
     *
     * @param  list<Line>  $lines
     * @param  Scale  $scale
     * @return array{bands: list<array{grade: string, count: int}>, average: float|null, passed: int, sat: int}
     */
    public static function spread(array $lines, array $scale): array
    {
        $counts = [];
        foreach ($scale['bands'] as $b) {
            $counts[$b['grade']] = 0;
        }
        $marks = [];
        $passed = 0;
        foreach ($lines as $l) {
            if ($l['grade'] !== null) {
                $counts[$l['grade']] = ($counts[$l['grade']] ?? 0) + 1;
            }
            if ($l['mark'] !== null) {
                $marks[] = $l['mark'];
            }
            $passed += $l['result'] === 'pass' ? 1 : 0;
        }
        $bands = [];
        foreach ($counts as $g => $n) {
            $bands[] = ['grade' => (string) $g, 'count' => $n];
        }

        return ['bands' => $bands, 'average' => $marks === [] ? null : round(array_sum($marks) / count($marks), 1), 'passed' => $passed,
            'sat' => count(array_filter($lines, fn (array $l): bool => $l['grade'] !== null))];
    }
}
