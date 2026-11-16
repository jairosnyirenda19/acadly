<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Contracts\AssessmentKind;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Contracts\MissingReason;
use Acadly\Modules\Assessment\Contracts\MissingRule;
use Acadly\Modules\Assessment\Domain\Assessment;
use Acadly\Modules\Assessment\Journeys\Marks\State;
use Acadly\Modules\Assessment\Journeys\Marks\StepId;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The marks sheet of one assessment (the approved Marks sheet): one row per
 * learner in the class on the day, the mark in force, the numbers, the
 * journey ladder and what the viewer may do.
 *
 * @phpstan-import-type ClassRow from Reach
 */
final class Sheets
{
    public function __construct(
        private readonly Reach $reach,
        private readonly AcademicCalendar $calendar,
        private readonly Teaching $teaching,
        private readonly EnrolmentDirectory $learners,
        private readonly Marks $marks,
        private readonly Plans $plans,
        private readonly Scales $scales,
        private readonly JourneyEngine $engine,
        private readonly Clock $clock,
    ) {}

    /** The assessment, when the viewer may see its class; otherwise "not found". */
    public function find(User $viewer, string $id): Assessment
    {
        $assessment = Ids::isValid($id) ? Assessment::query()->find($id) : null;

        if (! $assessment instanceof Assessment || ! $this->reach->sees($viewer, $assessment->class_id)) {
            throw new NotFoundHttpException;
        }

        return $assessment;
    }

    /** @return ClassRow */
    public function classOf(Assessment $assessment): array
    {
        return $this->calendar->classInForce($assessment->class_id) ?? throw new NotFoundHttpException;
    }

    /**
     * Learners with neither a mark nor a reason, and learners marked absent
     * where the grading plan asks the teacher to decide.
     *
     * @return array{missing: int, undecided: int}
     */
    public function unmarked(Assessment $assessment): array
    {
        $marks = $this->marks->inForce([$assessment->id])[$assessment->id] ?? [];
        $asks = $this->asks($assessment);
        $missing = 0;
        $undecided = 0;

        foreach ($this->learners->learnersIn($assessment->class_id, $assessment->heldOn()) as $learner) {
            $mark = $marks[$learner['id']] ?? null;
            $missing += $mark === null ? 1 : 0;
            $undecided += $asks && $mark !== null && $mark['reason'] === MissingReason::Absent->value ? 1 : 0;
        }

        return ['missing' => $missing, 'undecided' => $undecided];
    }

    /** @return array<string, mixed> */
    public function sheet(User $viewer, string $id): array
    {
        $a = $this->find($viewer, $id);
        $class = $this->classOf($a);
        $learners = $this->learners->learnersIn($a->class_id, $a->heldOn());
        $marks = $this->marks->inForce([$a->id])[$a->id] ?? [];
        $scale = $this->scales->forLevel($class['division_id'], $class['level_code']);
        $plan = $this->plans->inForce($class['division_id'], $class['level_code'], $a->period_id, $a->subject_id);
        $part = $a->counts ? Plans::partFor($plan, $a->kind) : null;
        $subject = $this->teaching->subjects([$a->subject_id])[$a->subject_id] ?? null;
        $names = self::userNames([$a->created_by, ...array_column($marks, 'entered_by')]);

        $rows = [];
        $values = [];

        foreach ($learners as $learner) {
            $mark = $marks[$learner['id']] ?? null;
            $percent = $mark !== null && $mark['value'] !== null ? Marks::percent($mark['value'], $a->out_of) : null;
            $graded = $percent !== null && $scale !== null ? Scales::grade($scale, $percent) : null;

            if ($percent !== null) {
                $values[] = $percent;
            }

            $rows[] = [
                'id' => $learner['id'], 'name' => $learner['name'], 'admission_number' => $learner['admission_number'],
                'value' => $mark['value'] ?? null, 'reason' => $mark['reason'] ?? null, 'percent' => $percent, 'grade' => $graded['grade'] ?? null,
                'entered_by' => $mark !== null ? ($names[$mark['entered_by']] ?? '') : null, 'entered_at' => $mark['entered_at'] ?? null,
                'corrected' => $mark['corrected'] ?? false, 'note' => $mark['note'] ?? null,
            ];
        }

        $state = State::tryFrom($a->state) ?? State::Marking;
        $owns = $this->reach->owns($viewer, $a);
        $steps = [];

        foreach ($this->engine->available($a, $viewer) as $step) {
            $steps[$step->step] = $step;
        }

        $submit = $steps[StepId::Submit->value] ?? null;
        $lock = $steps[StepId::Lock->value] ?? null;
        $return = $steps[StepId::Return->value] ?? null;
        $mayLock = $this->reach->may($viewer, AssessmentPermission::Lock, $a->class_id);
        $history = $this->history($a);

        return [
            'assessment' => [
                'id' => $a->id, 'class_id' => $a->class_id, 'class' => $class['name'], 'level' => $this->reach->levelLabel($class['division_id'], $class['level_code']),
                'subject_id' => $a->subject_id, 'subject' => $subject['name'] ?? '', 'kind' => $a->kind, 'kind_label' => AssessmentKind::tryFrom($a->kind)?->label() ?? $a->kind,
                'title' => $a->title, 'held_on' => $a->heldOn(), 'out_of' => $a->out_of, 'counts' => $a->counts,
                'part' => $part !== null ? ['key' => $part['key'], 'label' => $part['label'], 'weight' => $part['weight']] : null,
                'state' => $state->value, 'state_label' => $state->label(), 'set_by' => $names[$a->created_by] ?? '',
            ],
            'learners' => $rows,
            'stats' => [
                'learners' => count($rows),
                'marked' => count(array_filter($rows, fn (array $r): bool => $r['value'] !== null || $r['reason'] !== null)),
                'missing' => count(array_filter($rows, fn (array $r): bool => $r['value'] === null && $r['reason'] === null)),
                'absent' => count(array_filter($rows, fn (array $r): bool => $r['reason'] !== null)),
                'average' => $values === [] ? null : round(array_sum($values) / count($values), 1),
                'highest' => $values === [] ? null : max($values),
                'lowest' => $values === [] ? null : min($values),
                'below_half' => count(array_filter($values, fn (float $v): bool => $v < 50)),
            ],
            'scale' => $scale !== null ? ['id' => $scale['id'], 'name' => $scale['name']] : null,
            'ladder' => $this->ladder($a, $state, $history, $names),
            'history' => $history,
            'reasons' => MissingReason::choices(),
            'can_enter' => $state->open() && $a->heldOn() <= $this->clock->today() && $owns && $this->reach->may($viewer, AssessmentPermission::Enter, $a->class_id),
            'can_submit' => $submit !== null && $submit->permitted && $owns && $submit->blockedReason === null,
            'submit_blocked' => $submit !== null && $submit->blockedReason !== null ? ['reason' => $submit->blockedReason, 'remedy' => (string) $submit->remedy] : null,
            'can_lock' => $lock !== null && $lock->permitted,
            'can_return' => $return !== null && $return->permitted,
            'lock_note' => $lock !== null && ! $lock->permitted && $mayLock ? 'You cannot lock marks you submitted.' : null,
            'can_correct' => $state === State::Locked && $mayLock,
        ];
    }

    /** Whether the part this assessment counts in asks the teacher to decide about an absent learner. */
    private function asks(Assessment $a): bool
    {
        if (! $a->counts) {
            return false;
        }

        $class = $this->calendar->classInForce($a->class_id);

        if ($class === null) {
            return false;
        }

        $part = Plans::partFor($this->plans->inForce($class['division_id'], $class['level_code'], $a->period_id, $a->subject_id), $a->kind);

        return $part !== null && $part['missing'] === MissingRule::Ask->value;
    }

    /** @return list<array{step: string, outcome: string, by: string, at: string, reason: string|null}> */
    private function history(Assessment $a): array
    {
        $rows = DB::table('journey_step_completions')->where('subject_type', $a->auditType())->where('subject_id', $a->id)->orderBy('seq')
            ->get(['step', 'outcome', 'actor_user_id', 'occurred_at', 'reason']);
        $names = self::userNames(array_values(array_filter(array_map(fn (object $r): ?string => Row::maybeText($r, 'actor_user_id'), $rows->all()), is_string(...))));

        return array_values(array_map(fn (object $r): array => [
            'step' => Row::text($r, 'step'), 'outcome' => Row::text($r, 'outcome'),
            'by' => $names[Row::maybeText($r, 'actor_user_id') ?? ''] ?? '', 'at' => Row::text($r, 'occurred_at'), 'reason' => Row::maybeText($r, 'reason'),
        ], $rows->all()));
    }

    /**
     * Set, marking, submitted, locked: each with whether it is done, and who and when.
     *
     * @param  list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>  $history
     * @param  array<string, string>  $names
     * @return list<array{key: string, label: string, done: bool, current: bool, by: string|null, at: string|null}>
     */
    private function ladder(Assessment $a, State $state, array $history, array $names): array
    {
        $last = fn (string $step): ?array => array_values(array_filter($history, fn (array $h): bool => $h['step'] === $step))[0] ?? null;
        $submitted = null;

        foreach ($history as $h) {
            $submitted = $h['step'] === StepId::Submit->value ? $h : $submitted;
        }

        $locked = $last(StepId::Lock->value);

        return [
            ['key' => 'set', 'label' => 'Set', 'done' => true, 'current' => false, 'by' => $names[$a->created_by] ?? null, 'at' => $a->created_at?->toIso8601String()],
            ['key' => 'marking', 'label' => $state === State::Returned ? State::Returned->label() : State::Marking->label(), 'done' => ! $state->open(), 'current' => $state->open(), 'by' => null, 'at' => null],
            ['key' => 'submitted', 'label' => State::Submitted->label(), 'done' => $state === State::Locked, 'current' => $state === State::Submitted,
                'by' => $state->open() ? null : $submitted['by'] ?? null, 'at' => $state->open() ? null : $submitted['at'] ?? null],
            ['key' => 'locked', 'label' => State::Locked->label(), 'done' => $state === State::Locked, 'current' => $state === State::Locked, 'by' => $locked['by'] ?? null, 'at' => $locked['at'] ?? null],
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public static function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, Ids::isValid(...))));

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('users')->whereIn('id', $ids)->get(['id', 'name']) as $u) {
            $out[Row::text($u, 'id')] = Row::text($u, 'name');
        }

        return $out;
    }
}
