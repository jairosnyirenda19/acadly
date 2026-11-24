<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Contracts\ObservationStep;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The rooms of levels that record observations, and what was observed there
 * (D-094). A room is a class; a learning area is a subject. Anyone who holds
 * an assessment permission at the room sees it; anyone else is told "not
 * found".
 *
 * @phpstan-type ClassRow array{id: string, name: string, year_id: string, division_id: string, campus_id: string, level_code: string}
 * @phpstan-type TrailRow array{observed_on: string, step: string, focus: string|null, note: string|null}
 */
final class Observations
{
    public function __construct(
        private readonly Reach $reach,
        private readonly AcademicCalendar $calendar,
        private readonly Teaching $teaching,
        private readonly EnrolmentDirectory $directory,
        private readonly Clock $clock,
    ) {}

    /**
     * The rooms of this year the viewer sees, in levels that record observations.
     *
     * @return list<ClassRow>
     */
    public function rooms(User $who): array
    {
        $year = $this->calendar->currentYear();

        return $year === null ? [] : array_values(array_filter($this->calendar->classesInYear($year['id']),
            fn (array $c): bool => ! $this->reach->takesMarks($c['division_id']) && $this->reach->sees($who, $c['id'])));
    }

    /**
     * A room the viewer sees, or "not found". With a permission, it is also checked.
     *
     * @return ClassRow
     */
    public function room(User $who, string $classId, ?AssessmentPermission $permission = null): array
    {
        $class = $this->calendar->classInForce($classId);

        if ($class === null || $this->reach->takesMarks($class['division_id']) || ! $this->reach->sees($who, $classId)) {
            throw new NotFoundHttpException;
        }

        if ($permission !== null && ! $this->reach->may($who, $permission, $classId)) {
            throw new NotFoundHttpException;
        }

        return $class;
    }

    /**
     * The room on a day: its learning areas and how many children were seen in
     * each this week, the children not seen this week, and the reviews of the term.
     *
     * @return array{room: array{id: string, name: string, level: string}, day: string, week_from: string, period: array{id: string, name: string, ends_on: string}|null, areas: list<array{id: string, name: string, short: string, seen: int, observations: int}>, children: list<array{id: string, name: string, seen_this_week: bool, last_seen: string|null}>, reviews: array{children: int, started: int, ready: int, checked: int, shared: int}, can: array{observe: bool, review: bool}}
     */
    public function today(User $who, string $classId, ?string $day = null): array
    {
        $class = $this->room($who, $classId);
        $day ??= $this->clock->today();
        $from = CarbonImmutable::parse($day)->startOfWeek()->toDateString();
        $period = $this->teaching->periodAt($class['division_id'], $day);
        $children = $this->directory->learnersIn($classId, $day);
        $ids = array_column($children, 'id');
        $areas = $this->teaching->subjectsFor($class['division_id'], $class['level_code']);

        $week = $ids === [] ? [] : DB::table('assessment_observations')->where('class_id', $classId)->whereBetween('observed_on', [$from, $day])
            ->whereIn('learner_id', $ids)->where('step', '!=', ObservationStep::NotSeen->value)->get(['learner_id', 'subject_id'])->all();
        $seen = [];
        $count = [];
        $byArea = [];

        foreach ($week as $row) {
            $learner = Row::text($row, 'learner_id');
            $subject = Row::text($row, 'subject_id');
            $seen[$learner] = true;
            $byArea[$subject][$learner] = true;
            $count[$subject] = ($count[$subject] ?? 0) + 1;
        }

        $last = [];

        if ($ids !== []) {
            foreach (DB::table('assessment_observations')->whereIn('learner_id', $ids)->where('step', '!=', ObservationStep::NotSeen->value)
                ->groupBy('learner_id')->selectRaw('learner_id, max(observed_on) as last_on')->get() as $row) {
                $last[Row::text($row, 'learner_id')] = substr(Row::text($row, 'last_on'), 0, 10);
            }
        }

        $reviews = ['children' => count($ids), 'started' => 0, 'ready' => 0, 'checked' => 0, 'shared' => 0];

        if ($period !== null && $ids !== []) {
            foreach (DB::table('progress_reviews')->where('period_id', $period['id'])->whereIn('learner_id', $ids)->pluck('state')->all() as $state) {
                $reviews['started']++;
                $key = is_string($state) && in_array($state, ['ready', 'checked', 'shared'], true) ? $state : null;

                if ($key !== null) {
                    $reviews[$key]++;
                }
            }
        }

        return [
            'room' => ['id' => $class['id'], 'name' => $class['name'], 'level' => $this->reach->levelLabel($class['division_id'], $class['level_code'])],
            'day' => $day,
            'week_from' => $from,
            'period' => $period === null ? null : ['id' => $period['id'], 'name' => $period['name'], 'ends_on' => $period['ends_on']],
            'areas' => array_map(fn (array $a): array => ['id' => $a['id'], 'name' => $a['name'], 'short' => $a['short'],
                'seen' => count($byArea[$a['id']] ?? []), 'observations' => $count[$a['id']] ?? 0], $areas),
            'children' => array_map(fn (array $c): array => ['id' => $c['id'], 'name' => $c['name'], 'seen_this_week' => isset($seen[$c['id']]),
                'last_seen' => $last[$c['id']] ?? null], $children),
            'reviews' => $reviews,
            'can' => ['observe' => $this->reach->may($who, AssessmentPermission::Observe, $classId), 'review' => $this->reach->may($who, AssessmentPermission::Review, $classId)],
        ];
    }

    /**
     * What was recorded in a room for one learning area on a day: the newest step of each child.
     *
     * @return array{room: array{id: string, name: string}, day: string, subject: array{id: string, name: string}, focus: string|null, children: list<array{id: string, name: string, step: string|null, note: string|null}>, areas: list<array{id: string, name: string}>, steps: list<array{key: string, label: string, strokes: int}>, can_observe: bool}
     */
    public function day(User $who, string $classId, ?string $subjectId, ?string $day): array
    {
        $class = $this->room($who, $classId);
        $day ??= $this->clock->today();
        $areas = $this->teaching->subjectsFor($class['division_id'], $class['level_code']);
        // The learning area asked for, or the first one.
        $subject = $areas[0] ?? null;

        foreach ($areas as $a) {
            if ($a['id'] === $subjectId) {
                $subject = $a;
            }
        }

        if ($subject === null) {
            throw new NotFoundHttpException;
        }

        $marks = [];
        $focus = null;

        foreach (DB::table('assessment_observations')->where('class_id', $classId)->where('subject_id', $subject['id'])->where('observed_on', $day)
            ->orderBy('created_at')->get() as $row) {
            $marks[Row::text($row, 'learner_id')] = ['step' => Row::text($row, 'step'), 'note' => Row::maybeText($row, 'note')];
            $focus = Row::maybeText($row, 'focus') ?? $focus;
        }

        return [
            'room' => ['id' => $class['id'], 'name' => $class['name']],
            'day' => $day,
            'subject' => ['id' => $subject['id'], 'name' => $subject['name']],
            'focus' => $focus,
            'children' => array_map(fn (array $c): array => ['id' => $c['id'], 'name' => $c['name'], 'step' => $marks[$c['id']]['step'] ?? null, 'note' => $marks[$c['id']]['note'] ?? null],
                $this->directory->learnersIn($classId, $day)),
            'areas' => array_map(fn (array $a): array => ['id' => $a['id'], 'name' => $a['name']], $areas),
            'steps' => self::steps(),
            'can_observe' => $this->reach->may($who, AssessmentPermission::Observe, $classId),
        ];
    }

    /**
     * A child's observations in a term, learning area by learning area, oldest first.
     * Where a day was recorded twice, the newest counts.
     *
     * @return array<string, list<TrailRow>> subject id => observations
     */
    public function trail(string $learnerId, string $periodId): array
    {
        $byDay = [];

        foreach (DB::table('assessment_observations')->where('learner_id', $learnerId)->where('period_id', $periodId)->orderBy('created_at')->get() as $row) {
            $subject = Row::text($row, 'subject_id');
            $on = substr(Row::text($row, 'observed_on'), 0, 10);
            $byDay[$subject][$on] = ['observed_on' => $on, 'step' => Row::text($row, 'step'), 'focus' => Row::maybeText($row, 'focus'), 'note' => Row::maybeText($row, 'note')];
        }

        $out = [];

        foreach ($byDay as $subject => $days) {
            ksort($days);
            $out[$subject] = array_values($days);
        }

        return $out;
    }

    /** How many days a child was seen in a term. */
    public function seenIn(string $learnerId, string $periodId): int
    {
        return DB::table('assessment_observations')->where('learner_id', $learnerId)->where('period_id', $periodId)
            ->where('step', '!=', ObservationStep::NotSeen->value)->distinct()->count('observed_on');
    }

    public function roomScope(string $classId): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $classId);
    }

    /**
     * The learning areas of a room, in order.
     *
     * @param  ClassRow  $room
     * @return list<array{id: string, name: string, short: string, division_id: string}>
     */
    public function areasOf(array $room): array
    {
        return $this->teaching->subjectsFor($room['division_id'], $room['level_code']);
    }

    public function periodName(string $periodId): string
    {
        return $this->teaching->period($periodId)['name'] ?? '';
    }

    /** @return list<array{key: string, label: string, strokes: int}> */
    public static function steps(): array
    {
        return array_map(fn (ObservationStep $s): array => ['key' => $s->value, 'label' => StepWords::label($s), 'strokes' => $s->strokes()], ObservationStep::cases());
    }
}
