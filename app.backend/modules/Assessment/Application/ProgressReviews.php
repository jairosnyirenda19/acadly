<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Contracts\ObservationStep;
use Acadly\Modules\Assessment\Domain\ProgressReview;
use Acadly\Modules\Assessment\Journeys\Review\State;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Progress reviews (D-094): one per child each term, read by the rooms the
 * viewer sees. The family sees a review only once it is shared, and only
 * steps and words: no marks, no ranks, no other child.
 *
 * @phpstan-import-type ClassRow from Observations
 * @phpstan-import-type TrailRow from Observations
 */
final class ProgressReviews
{
    public function __construct(
        private readonly Observations $observations,
        private readonly Reach $reach,
        private readonly Teaching $teaching,
        private readonly EnrolmentDirectory $directory,
        private readonly Clock $clock,
        private readonly AcademicCalendar $calendar,
    ) {}

    /**
     * Every child of the rooms the viewer sees, with the review of the room's term.
     *
     * @return array{children: list<array{learner_id: string, name: string, room_id: string, room: string, review_id: string|null, state: string|null, state_label: string, steps: list<string|null>, by: string|null, updated_at: string|null}>, areas: int, totals: array{children: int, started: int, ready: int, checked: int, shared: int}, can_review: bool}
     */
    public function index(User $who): array
    {
        $today = $this->clock->today();
        $out = [];
        $totals = ['children' => 0, 'started' => 0, 'ready' => 0, 'checked' => 0, 'shared' => 0];
        $canReview = false;
        $most = 0;

        foreach ($this->observations->rooms($who) as $room) {
            $period = $this->teaching->periodAt($room['division_id'], $today);
            $areas = $this->teaching->subjectsFor($room['division_id'], $room['level_code']);
            $most = max($most, count($areas));
            $canReview = $canReview || $this->reach->may($who, AssessmentPermission::Review, $room['id']);
            $children = $this->directory->learnersIn($room['id'], $today);
            $reviews = $period === null ? [] : ProgressReview::query()->where('period_id', $period['id'])
                ->whereIn('learner_id', array_column($children, 'id'))->get()->keyBy('learner_id')->all();
            $steps = $this->stepsOf(array_values(array_map(fn (ProgressReview $r): string => $r->id, $reviews)));
            $names = self::names(array_values(array_map(fn (ProgressReview $r): string => $r->drafted_by, $reviews)));

            foreach ($children as $child) {
                $review = $reviews[$child['id']] ?? null;
                $totals['children']++;

                if ($review instanceof ProgressReview) {
                    $totals['started']++;

                    if (in_array($review->state, ['ready', 'checked', 'shared'], true)) {
                        $totals[$review->state]++;
                    }
                }

                $out[] = [
                    'learner_id' => $child['id'], 'name' => $child['name'], 'room_id' => $room['id'], 'room' => $room['name'],
                    'review_id' => $review?->id,
                    'state' => $review?->state,
                    'state_label' => $review instanceof ProgressReview ? State::from($review->state)->label() : 'Not started',
                    'steps' => array_map(fn (array $a): ?string => $review instanceof ProgressReview ? ($steps[$review->id][$a['id']] ?? null) : null, $areas),
                    'by' => $review instanceof ProgressReview ? ($names[$review->drafted_by] ?? null) : null,
                    'updated_at' => $review?->updated_at->toIso8601String(),
                ];
            }
        }

        return ['children' => $out, 'areas' => $most, 'totals' => $totals, 'can_review' => $canReview];
    }

    /**
     * One child's review for the term: the step in each learning area beside
     * the term's observations, the two notes, and what the viewer may do.
     *
     * @return array{learner: array{id: string, name: string, room_id: string, room: string}, period: array{id: string, name: string, ends_on: string}, review: array{id: string|null, state: string, state_label: string, family_note: string, home_note: string, drafted_by: string|null, shared_at: string|null}, areas: list<array{id: string, name: string, step: string|null, trail: list<TrailRow>}>, steps: list<array{key: string, label: string, strokes: int}>, can: array{edit: bool, submit: bool, check: bool, return: bool, share: bool}}
     */
    public function one(User $who, string $learnerId): array
    {
        [$room, $period] = $this->placeOf($who, $learnerId);
        $review = ProgressReview::query()->where('learner_id', $learnerId)->where('period_id', $period['id'])->first();
        $steps = $review instanceof ProgressReview ? ($this->stepsOf([$review->id])[$review->id] ?? []) : [];
        $trail = $this->observations->trail($learnerId, $period['id']);
        $state = $review instanceof ProgressReview ? State::from($review->state) : State::Draft;
        $observe = $this->reach->may($who, AssessmentPermission::Observe, $room['id']);
        $reviewer = $this->reach->may($who, AssessmentPermission::Review, $room['id']);
        $name = $this->directory->names([$learnerId])[$learnerId] ?? '';

        return [
            'learner' => ['id' => $learnerId, 'name' => $name, 'room_id' => $room['id'], 'room' => $room['name']],
            'period' => ['id' => $period['id'], 'name' => $period['name'], 'ends_on' => $period['ends_on']],
            'review' => [
                'id' => $review?->id,
                'state' => $state->value,
                'state_label' => $review instanceof ProgressReview ? $state->label() : 'Not started',
                'family_note' => $review->family_note ?? '',
                'home_note' => $review->home_note ?? '',
                'drafted_by' => $review instanceof ProgressReview ? (self::names([$review->drafted_by])[$review->drafted_by] ?? null) : null,
                'shared_at' => $review?->shared_at?->toIso8601String(),
            ],
            'areas' => array_map(fn (array $a): array => ['id' => $a['id'], 'name' => $a['name'], 'step' => $steps[$a['id']] ?? null, 'trail' => $trail[$a['id']] ?? []],
                $this->teaching->subjectsFor($room['division_id'], $room['level_code'])),
            'steps' => array_values(array_filter(Observations::steps(), fn (array $s): bool => $s['key'] !== ObservationStep::NotSeen->value)),
            'can' => [
                'edit' => $observe && $state === State::Draft,
                'submit' => $observe && $state === State::Draft,
                'check' => $reviewer && $state === State::Ready,
                'return' => $reviewer && ($state === State::Ready || $state === State::Checked),
                'share' => $reviewer && $state === State::Checked,
            ],
        ];
    }

    /**
     * The room and term a child's review belongs to, for a viewer who sees the room.
     *
     * @return array{0: ClassRow, 1: array{id: string, name: string, division_id: string, year_id: string, starts_on: string, ends_on: string}}
     */
    public function placeOf(User $who, string $learnerId): array
    {
        $place = $this->directory->placeOf($learnerId);

        if ($place === null || ! $place['open']) {
            throw new NotFoundHttpException;
        }

        $room = $this->observations->room($who, $place['class_id']);
        $period = $this->teaching->periodAt($room['division_id'], $this->clock->today());

        return $period === null ? throw new NotFoundHttpException : [$room, $period];
    }

    /** How many of the room's learning areas have no step yet. */
    public function areasWithoutStep(ProgressReview $review): int
    {
        $class = $this->calendar->classInForce($review->class_id);

        if ($class === null) {
            return 0;
        }

        $steps = $this->stepsOf([$review->id])[$review->id] ?? [];
        $missing = 0;

        foreach ($this->teaching->subjectsFor($class['division_id'], $class['level_code']) as $area) {
            $missing += isset($steps[$area['id']]) ? 0 : 1;
        }

        return $missing;
    }

    /**
     * The newest step of each learning area, review by review.
     *
     * @param  list<string>  $reviewIds
     * @return array<string, array<string, string>> review id => subject id => step
     */
    public function stepsOf(array $reviewIds): array
    {
        $out = [];

        if ($reviewIds === []) {
            return $out;
        }

        foreach (DB::table('progress_review_steps')->whereIn('review_id', $reviewIds)->orderBy('created_at')->get() as $row) {
            $out[Row::text($row, 'review_id')][Row::text($row, 'subject_id')] = Row::text($row, 'step');
        }

        return $out;
    }

    /**
     * The newest review shared with the family, as the family sees it.
     *
     * @return array{period: string, room: string, shared_at: string, areas: list<array{name: string, step: string, label: string, strokes: int}>, family_note: string, home_note: string|null}|null
     */
    public function shared(string $learnerId): ?array
    {
        $review = ProgressReview::query()->where('learner_id', $learnerId)->where('state', State::Shared->value)->orderByDesc('shared_at')->first();

        if (! $review instanceof ProgressReview) {
            return null;
        }

        $steps = $this->stepsOf([$review->id])[$review->id] ?? [];
        $subjects = $this->teaching->subjects(array_keys($steps));
        $period = $this->teaching->period($review->period_id);
        $room = $this->calendar->classNames([$review->class_id])[$review->class_id]['name'] ?? '';
        $areas = [];

        foreach ($steps as $subjectId => $step) {
            $s = ObservationStep::tryFrom($step);

            if ($s !== null && isset($subjects[$subjectId])) {
                $areas[] = ['name' => $subjects[$subjectId]['name'], 'step' => $s->value, 'label' => $s->label(), 'strokes' => $s->strokes()];
            }
        }

        return [
            'period' => $period['name'] ?? '',
            'room' => $room,
            'shared_at' => $review->shared_at?->toIso8601String() ?? '',
            'areas' => $areas,
            'family_note' => $review->family_note ?? '',
            'home_note' => $review->home_note,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private static function names(array $ids): array
    {
        $out = [];

        foreach (DB::table('users')->whereIn('id', array_values(array_unique($ids)))->get(['id', 'name']) as $row) {
            $out[Row::text($row, 'id')] = Row::text($row, 'name');
        }

        return $out;
    }
}
