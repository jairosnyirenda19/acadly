<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Application\SubjectChoices;
use Acadly\Modules\Assessment\Contracts\AssessmentAudit;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Saves the electives each learner of a class takes this year (D-117). Only
 * a change is written; the first save for a level writes every choice and
 * turns choices on for the level. Marks already entered stay with their
 * subject.
 */
final class ChoiceActions
{
    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly EnrolmentDirectory $learners,
        private readonly SubjectChoices $choices,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, list<string>>  $takes  learner id => the electives they take
     * @return int the number of choices changed
     */
    public function save(User $by, string $classId, array $takes): int
    {
        $class = $this->calendar->classInForce($classId) ?? throw new NotFoundHttpException;
        $at = ScopeRef::of(ScopeType::ClassGroup, $classId);
        $this->access->check($by, AssessmentPermission::Plan, $at);
        $electives = array_column($this->choices->electives($class), 'id');
        $inClass = array_column($this->learners->learnersIn($classId, $this->clock->today()), 'id');

        if ($electives === []) {
            throw ValidationException::withMessages(['learners' => ['This level has no electives. Mark a subject as an elective in Subjects first.']]);
        }

        foreach ($takes as $learner => $subjects) {
            if (! in_array($learner, $inClass, true)) {
                throw ValidationException::withMessages(['learners' => ['A learner is not in this class.']]);
            }

            if (array_diff($subjects, $electives) !== []) {
                throw ValidationException::withMessages(['learners' => ['Choose only the electives of this level.']]);
            }
        }

        return DB::transaction(function () use ($by, $class, $at, $takes, $electives): int {
            $on = $this->choices->on($class);
            $current = $this->choices->chosen($class, array_map('strval', array_keys($takes)));
            $now = $this->clock->now();
            $changed = 0;

            foreach ($takes as $learner => $subjects) {
                foreach ($electives as $subject) {
                    $wanted = in_array($subject, $subjects, true);
                    $was = $current[$learner][$subject] ?? null;

                    if ($was === $wanted || ($on && $was === null && ! $wanted)) {
                        continue;
                    }

                    DB::table('assessment_subject_choices')->insert(['id' => Ids::next(), 'year_id' => $class['year_id'], 'division_id' => $class['division_id'],
                        'level_code' => $class['level_code'], 'learner_id' => $learner, 'subject_id' => $subject, 'takes' => $wanted, 'set_by' => $by->id, 'set_at' => $now]);
                    $changed++;
                }
            }

            if ($changed > 0) {
                $this->audit->record(AssessmentAudit::ChoicesSaved, actor: $this->audit->actorFor($by), at: $at, meta: ['class' => $class['id'], 'changed' => $changed]);
            }

            return $changed;
        });
    }
}
