<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application\Actions;

use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Application\Observations;
use Acadly\Modules\Assessment\Contracts\AssessmentAudit;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Contracts\ObservationStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records observations (D-094): one learning area, one day, a step for each
 * child seen. A child left out was simply not observed. Recording a child
 * again for the same day adds a new row, and the newest counts.
 */
final class ObservationActions
{
    public function __construct(
        private readonly Observations $observations,
        private readonly Teaching $teaching,
        private readonly EnrolmentDirectory $directory,
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<array{learner_id: string, step: string, note: string|null}>  $marks
     * @return int how many were recorded
     */
    public function record(User $by, string $classId, string $subjectId, string $day, ?string $focus, array $marks): int
    {
        $room = $this->observations->room($by, $classId);
        $at = ScopeRef::of(ScopeType::ClassGroup, $classId);
        $this->access->check($by, AssessmentPermission::Observe, $at);

        if (! in_array($subjectId, array_column($this->teaching->subjectsFor($room['division_id'], $room['level_code']), 'id'), true)) {
            throw ValidationException::withMessages(['subject_id' => ['Choose a learning area of this room.']]);
        }

        if ($day > $this->clock->today()) {
            throw ValidationException::withMessages(['observed_on' => ['An observation is recorded on the day or after it, never before.']]);
        }

        $period = $this->teaching->periodOn($room['division_id'], $day);

        if ($period === null) {
            throw ValidationException::withMessages(['observed_on' => ['The day is not in a term of this level.']]);
        }

        if ($marks === []) {
            throw ValidationException::withMessages(['marks' => ['Choose a step for at least one child.']]);
        }

        $inRoom = array_column($this->directory->learnersIn($classId, $day), 'id');

        foreach ($marks as $mark) {
            if (! in_array($mark['learner_id'], $inRoom, true)) {
                throw ValidationException::withMessages(['marks' => ['Each child must be in this room on the day.']]);
            }

            if (ObservationStep::tryFrom($mark['step']) === null) {
                throw ValidationException::withMessages(['marks' => ['Choose Starting, Growing, Can do it or Not seen.']]);
            }
        }

        $focus = $focus !== null && trim($focus) !== '' ? mb_substr(trim($focus), 0, 120) : null;

        DB::transaction(function () use ($by, $room, $at, $subjectId, $day, $period, $focus, $marks): void {
            $now = $this->clock->now();

            foreach ($marks as $mark) {
                DB::table('assessment_observations')->insert([
                    'id' => Ids::next(), 'learner_id' => $mark['learner_id'], 'class_id' => $room['id'], 'division_id' => $room['division_id'],
                    'period_id' => $period['id'], 'subject_id' => $subjectId, 'observed_on' => $day, 'focus' => $focus, 'step' => $mark['step'],
                    'note' => $mark['note'] !== null && trim($mark['note']) !== '' ? mb_substr(trim($mark['note']), 0, 255) : null,
                    'recorded_by' => $by->id, 'created_at' => $now,
                ]);
            }

            $this->audit->record(AssessmentAudit::Observed, actor: $this->audit->actorFor($by), at: $at,
                meta: ['room' => $room['id'], 'area' => $subjectId, 'day' => $day, 'children' => count($marks)]);
        });

        return count($marks);
    }
}
