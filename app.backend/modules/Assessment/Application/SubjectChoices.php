<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Domain\Assessment;
use Illuminate\Support\Facades\DB;

/**
 * Subject choices (D-117). A core subject is taken by every learner of its
 * levels. An elective is taken by everyone too, until the school saves
 * choices for the level in the year: from then on, only by the learners who
 * chose it. Marks sheets list only takers, and positions go by average.
 *
 * @phpstan-import-type ClassRow from Reach
 * @phpstan-import-type Subject from Teaching
 */
final class SubjectChoices
{
    public function __construct(
        private readonly Teaching $teaching,
        private readonly AcademicCalendar $calendar,
        private readonly EnrolmentDirectory $learners,
    ) {}

    /**
     * Whether the level of the class has choices in its year.
     *
     * @param  ClassRow  $class
     */
    public function on(array $class): bool
    {
        return DB::table('assessment_subject_choices')->where('year_id', $class['year_id'])->where('division_id', $class['division_id'])
            ->where('level_code', $class['level_code'])->exists();
    }

    /**
     * The level's electives, in order.
     *
     * @param  ClassRow  $class
     * @return list<Subject>
     */
    public function electives(array $class): array
    {
        return array_values(array_filter($this->teaching->subjectsFor($class['division_id'], $class['level_code']), fn (array $s): bool => ! $s['core']));
    }

    /**
     * The choice in force for each learner and elective.
     *
     * @param  ClassRow  $class
     * @param  list<string>  $learnerIds
     * @return array<string, array<string, bool>> learner => subject => takes
     */
    public function chosen(array $class, array $learnerIds): array
    {
        $out = [];

        foreach ($learnerIds === [] ? [] : DB::table('assessment_subject_choices')->where('year_id', $class['year_id'])->whereIn('learner_id', $learnerIds)
            ->orderBy('set_at')->orderBy('id')->get(['learner_id', 'subject_id', 'takes']) as $r) {
            $out[Row::text($r, 'learner_id')][Row::text($r, 'subject_id')] = $r->takes === true;
        }

        return $out;
    }

    /**
     * Who takes each subject, of these learners.
     *
     * @param  ClassRow  $class
     * @param  list<string>  $subjectIds
     * @param  list<string>  $learnerIds
     * @return array<string, list<string>> subject => learner ids
     */
    public function takers(array $class, array $subjectIds, array $learnerIds): array
    {
        $out = array_fill_keys($subjectIds, $learnerIds);

        if (! $this->on($class)) {
            return $out;
        }

        $electives = array_column($this->electives($class), 'id');
        $chosen = $this->chosen($class, $learnerIds);

        foreach ($subjectIds as $subject) {
            if (in_array($subject, $electives, true)) {
                $out[$subject] = array_values(array_filter($learnerIds, fn (string $l): bool => $chosen[$l][$subject] ?? false));
            }
        }

        return $out;
    }

    /**
     * The learners of an assessment's sheet: those in the class on the day who take its subject.
     *
     * @return list<array{id: string, name: string, admission_number: string|null}>
     */
    public function learnersFor(Assessment $assessment): array
    {
        $all = $this->learners->learnersIn($assessment->class_id, $assessment->heldOn());
        $class = $this->calendar->classInForce($assessment->class_id);

        if ($class === null) {
            return $all;
        }

        $takers = $this->takers($class, [$assessment->subject_id], array_column($all, 'id'))[$assessment->subject_id];

        return array_values(array_filter($all, fn (array $l): bool => in_array($l['id'], $takers, true)));
    }
}
