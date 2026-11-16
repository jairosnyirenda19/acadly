<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Contracts\AssessmentKind;
use Acadly\Modules\Assessment\Contracts\OverallResult;
use Acadly\Modules\Assessment\Journeys\Marks\State;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Term grades as the screens show them (the approved Class results and
 * Learner results designs): a class's learners by subject, the working of
 * one grade, and one learner's grades and assessments this term. Grades so
 * far, from locked marks; nothing here is stored.
 *
 * @phpstan-import-type ClassRow from Reach
 * @phpstan-import-type Period from Teaching
 */
final class Results
{
    public function __construct(
        private readonly Reach $reach,
        private readonly AcademicCalendar $calendar,
        private readonly Teaching $teaching,
        private readonly EnrolmentDirectory $learners,
        private readonly TermGrades $grades,
        private readonly Marks $marks,
        private readonly Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function forClass(User $viewer, string $classId, ?string $periodId): array
    {
        [$class, $period] = $this->place($viewer, $classId, $periodId);
        $learners = $this->learners->learnersIn($class['id'], min($this->clock->today(), $period['ends_on']));
        $result = $this->grades->forClass($class, $period['id'], array_column($learners, 'id'));
        $scale = $result['scale'];
        $rows = [];
        $below = 0;
        $totals = [];

        foreach ($learners as $learner) {
            $bySubject = [];
            $cells = [];

            foreach ($result['subjects'] as $subject) {
                $w = $result['grades'][$subject['id']][$learner['id']];
                $bySubject[$subject['id']] = $w;
                $cells[] = ['subject_id' => $subject['id'], 'percent' => $w['percent'], 'grade' => $w['grade'], 'ready' => $w['ready'], 'passes' => $w['passes']];
            }

            $fails = count(array_filter($cells, fn (array $c): bool => $c['passes'] === false)) > 0;
            $below += $fails ? 1 : 0;
            $totals[$learner['id']] = TermGrades::total($bySubject);
            $rows[] = ['id' => $learner['id'], 'name' => $learner['name'], 'grades' => $cells, 'summary' => TermGrades::summary($scale, $bySubject),
                'total' => $totals[$learner['id']], 'position' => null, 'below_pass' => $fails];
        }

        $positions = TermGrades::positions($totals);

        foreach ($rows as $i => $row) {
            $rows[$i]['position'] = $positions[$row['id']] ?? null;
        }

        $subjects = [];

        foreach ($result['subjects'] as $subject) {
            $all = $result['grades'][$subject['id']] ?? [];
            $waiting = null;

            foreach ($all as $w) {
                $waiting ??= $w['not_ready'];
            }

            $subjects[] = [...$subject, 'ready' => $all !== [] && $waiting === null, 'waiting' => $waiting];
        }

        return [
            'class' => ['id' => $class['id'], 'name' => $class['name'], 'level' => $this->reach->levelLabel($class['division_id'], $class['level_code'])],
            'period' => self::periodOut($period),
            'scale' => $scale !== null ? ['id' => $scale['id'], 'name' => $scale['name'], 'overall' => $scale['overall']->value, 'pass_from' => $scale['pass_from']] : null,
            'summary_kind' => ($scale['overall'] ?? OverallResult::AveragePercent)->value,
            'subjects' => $subjects,
            'learners' => $rows,
            'totals' => [
                'learners' => count($rows),
                'ranked' => count($positions),
                'subjects' => count($subjects),
                'subjects_ready' => count(array_filter($subjects, fn (array $s): bool => $s['ready'])),
                'below_pass' => $below,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function working(User $viewer, string $classId, string $learnerId, string $subjectId, ?string $periodId): array
    {
        [$class, $period] = $this->place($viewer, $classId, $periodId);
        $subject = $this->teaching->subjects([$subjectId])[$subjectId] ?? null;
        $name = $this->learners->names([$learnerId])[$learnerId] ?? null;

        if ($subject === null || $subject['division_id'] !== $class['division_id'] || $name === null) {
            throw new NotFoundHttpException;
        }

        $result = $this->grades->forClass($class, $period['id'], [$learnerId], $subjectId);
        $w = $result['grades'][$subjectId][$learnerId] ?? throw new NotFoundHttpException;
        $scale = $result['scale'];

        return [
            'learner' => ['id' => $learnerId, 'name' => $name],
            'subject' => ['id' => $subject['id'], 'name' => $subject['name']],
            'class' => ['id' => $class['id'], 'name' => $class['name']],
            'period' => self::periodOut($period),
            'scale' => $scale !== null ? ['id' => $scale['id'], 'name' => $scale['name']] : null,
            'working' => $w,
        ];
    }

    /**
     * One learner's term so far: a grade per subject and this term's assessments with the learner's mark.
     *
     * @return array<string, mixed>
     */
    public function learner(User $viewer, string $learnerId): array
    {
        $place = Ids::isValid($learnerId) ? $this->learners->placeOf($learnerId) : null;
        $class = $place !== null ? $this->calendar->classInForce($place['class_id']) : null;

        if ($class === null || ! $this->reach->sees($viewer, $class['id']) || ! $this->reach->takesMarks($class['division_id'])) {
            throw new NotFoundHttpException;
        }

        $period = $this->teaching->periodAt($class['division_id'], $this->clock->today());

        if ($period === null) {
            return ['learner_id' => $learnerId, 'class' => ['id' => $class['id'], 'name' => $class['name']], 'period' => null, 'scale' => null,
                'grades' => [], 'summary' => ['kind' => 'average_percent', 'value' => null, 'of' => 0], 'total' => null, 'position' => null, 'assessments' => []];
        }

        // The whole class, so the learner's position can be found.
        $classmates = array_column($this->learners->learnersIn($class['id'], min($this->clock->today(), $period['ends_on'])), 'id');
        $result = $this->grades->forClass($class, $period['id'], array_values(array_unique([...$classmates, $learnerId])));
        $scale = $result['scale'];
        $grades = [];
        $bySubject = [];
        $names = [];
        $totals = [];

        foreach ($result['subjects'] as $subject) {
            $w = $result['grades'][$subject['id']][$learnerId];
            $bySubject[$subject['id']] = $w;
            $names[$subject['id']] = $subject['name'];
            $grades[] = ['subject_id' => $subject['id'], 'subject' => $subject['name'], 'percent' => $w['percent'], 'grade' => $w['grade'], 'word' => $w['word'],
                'ready' => $w['ready'], 'not_ready' => $w['not_ready'], 'passes' => $w['passes']];
        }

        foreach ($classmates as $id) {
            $totals[$id] = TermGrades::total(array_map(fn (array $bySubjectOf): array => $bySubjectOf[$id], $result['grades']));
        }

        $assessments = $this->grades->assessments($class['id'], $period['id']);
        $marks = $this->marks->ofLearner($learnerId, array_column($assessments, 'id'));
        $rows = [];

        foreach (array_reverse($assessments) as $a) {
            $mark = $marks[$a['id']] ?? null;
            $locked = $a['state'] === State::Locked->value;
            $rows[] = [
                'id' => $a['id'], 'held_on' => $a['held_on'], 'subject' => $names[$a['subject_id']] ?? '', 'kind' => $a['kind'],
                'kind_label' => AssessmentKind::tryFrom($a['kind'])?->label() ?? $a['kind'], 'title' => $a['title'], 'counts' => $a['counts'],
                'state' => $a['state'], 'locked' => $locked, 'out_of' => $a['out_of'],
                'value' => $locked ? $mark['value'] ?? null : null, 'reason' => $locked ? $mark['reason'] ?? null : null,
                'percent' => $locked && $mark !== null && $mark['value'] !== null ? Marks::percent($mark['value'], $a['out_of']) : null,
            ];
        }

        return [
            'learner_id' => $learnerId,
            'class' => ['id' => $class['id'], 'name' => $class['name']],
            'period' => self::periodOut($period),
            'scale' => $scale !== null ? ['id' => $scale['id'], 'name' => $scale['name'], 'overall' => $scale['overall']->value] : null,
            'grades' => $grades,
            'summary' => TermGrades::summary($scale, $bySubject),
            'total' => TermGrades::total($bySubject),
            'position' => TermGrades::positions($totals)[$learnerId] ?? null,
            'assessments' => $rows,
        ];
    }

    /**
     * The class and term asked for, when the viewer may see the class.
     *
     * @return array{0: ClassRow, 1: Period}
     */
    private function place(User $viewer, string $classId, ?string $periodId): array
    {
        $class = $this->calendar->classInForce($classId);

        if ($class === null || ! $this->reach->sees($viewer, $class['id']) || ! $this->reach->takesMarks($class['division_id'])) {
            throw new NotFoundHttpException;
        }

        $period = $periodId !== null ? $this->teaching->period($periodId) : $this->teaching->periodAt($class['division_id'], $this->clock->today());

        if ($period === null || $period['division_id'] !== $class['division_id']) {
            throw new NotFoundHttpException;
        }

        return [$class, $period];
    }

    /**
     * @param  Period  $period
     * @return array{id: string, name: string, starts_on: string, ends_on: string}
     */
    private static function periodOut(array $period): array
    {
        return ['id' => $period['id'], 'name' => $period['name'], 'starts_on' => $period['starts_on'], 'ends_on' => $period['ends_on']];
    }
}
