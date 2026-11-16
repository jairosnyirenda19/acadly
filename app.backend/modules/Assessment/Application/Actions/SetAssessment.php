<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Assessment\Application\Plans;
use Acadly\Modules\Assessment\Application\Reach;
use Acadly\Modules\Assessment\Contracts\AssessmentAudit;
use Acadly\Modules\Assessment\Contracts\AssessmentKind;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Contracts\PlanMode;
use Acadly\Modules\Assessment\Domain\Assessment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sets an assessment (the approved New assessment screen). A teacher sets
 * one for a class and subject they are given in Teaching; someone who keeps
 * the grading plans may set one for any subject of the class. The term must
 * be open; whether it counts decides the part of the plan it lands in.
 */
final class SetAssessment
{
    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly Teaching $teaching,
        private readonly Reach $reach,
        private readonly Plans $plans,
        private readonly Blueprints $blueprints,
        private readonly JourneyEngine $engine,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @param array<array-key, mixed> $input {class_id, subject_id, kind, title, held_on, out_of, counts} */
    public function handle(User $by, array $input): Assessment
    {
        $class = $this->calendar->classInForce(is_string($input['class_id'] ?? null) ? $input['class_id'] : '');

        if ($class === null) {
            throw new NotFoundHttpException;
        }

        $at = ScopeRef::of(ScopeType::ClassGroup, $class['id']);
        $this->access->check($by, AssessmentPermission::Create, $at);

        $subjectId = is_string($input['subject_id'] ?? null) ? $input['subject_id'] : '';
        $kind = AssessmentKind::tryFrom(is_string($input['kind'] ?? null) ? $input['kind'] : '');
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $heldOn = is_string($input['held_on'] ?? null) ? $input['held_on'] : '';
        $outOf = $input['out_of'] ?? null;
        $errors = [];

        if (! $this->reach->takesMarks($class['division_id'])) {
            throw ValidationException::withMessages(['class_id' => ['This level records observations, not marks.']]);
        }

        if (! in_array($subjectId, array_column($this->teaching->subjectsFor($class['division_id'], $class['level_code']), 'id'), true)) {
            $errors['subject_id'] = ['Choose a subject this class takes.'];
        } elseif (! $this->teaching->teaches($by->id, $class['id'], $subjectId) && ! $this->access->allows($by, AssessmentPermission::Plan, $at)) {
            $errors['subject_id'] = ['You may set assessments only for the subjects you teach in this class.'];
        }

        if ($kind === null) {
            $errors['kind'] = ['Choose the kind of assessment.'];
        }

        if ($title === '' || mb_strlen($title) > 80) {
            $errors['title'] = ['Give the assessment a title of up to 80 characters.'];
        }

        if (! is_int($outOf) || $outOf < 1 || $outOf > 1000) {
            $errors['out_of'] = ['Give the most marks as a whole number from 1 to 1000.'];
        }

        $period = null;

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $heldOn, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $errors['held_on'] = ['Give the day it is held as YYYY-MM-DD.'];
        } else {
            $period = $this->teaching->periodOn($class['division_id'], $heldOn);

            if ($period === null) {
                $errors['held_on'] = ['The day is not in a term of this level.'];
            } elseif ($period['ends_on'] < $this->clock->today()) {
                $errors['held_on'] = ['The term has ended. Assessments are set while the term is open.'];
            }
        }

        if ($errors !== [] || $kind === null || $period === null || ! is_int($outOf)) {
            throw ValidationException::withMessages($errors);
        }

        $plan = $this->plans->inForce($class['division_id'], $class['level_code'], $period['id'], $subjectId);
        $part = Plans::partFor($plan, $kind->value);
        $counts = ($input['counts'] ?? true) !== false;

        if ($plan['mode'] === PlanMode::ExamOnly->value && $kind !== AssessmentKind::EndOfTerm) {
            $counts = false;
        }

        if ($counts && $part === null) {
            throw ValidationException::withMessages(['counts' => ['This kind does not count in the grading plan for this term. Set it as practice.']]);
        }

        return DB::transaction(function () use ($by, $class, $subjectId, $period, $kind, $title, $heldOn, $outOf, $counts, $part, $at): Assessment {
            $ref = $this->blueprints->newest('assessment.marks');
            $assessment = Assessment::query()->create([
                'class_id' => $class['id'], 'subject_id' => $subjectId, 'period_id' => $period['id'], 'kind' => $kind->value, 'title' => $title,
                'held_on' => $heldOn, 'out_of' => $outOf, 'counts' => $counts, 'part_key' => $counts ? $part['key'] : null,
                'created_by' => $by->id, 'state' => $this->engine->initialState($ref), 'journey_ref' => (string) $ref,
            ]);
            $this->audit->record(AssessmentAudit::AssessmentSet, subject: $assessment, actor: $this->audit->actorFor($by), at: $at,
                meta: ['subject' => $subjectId, 'kind' => $kind->value, 'held_on' => $heldOn, 'out_of' => $outOf, 'counts' => $counts]);

            return $assessment;
        });
    }
}
