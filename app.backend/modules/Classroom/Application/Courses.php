<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Classroom\Contracts\ClassroomPermission;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Acadly\Modules\Programmes\Contracts\ProgrammesSlot;
use Acadly\Modules\Programmes\Contracts\StudyShape;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The courses Classroom works on (D-104): the offerings of a college
 * division's semesters, read through the Programmes contract. A course page
 * is run by the lecturers named on the offering who hold `classroom.teach`,
 * and seen by holders of `classroom.view`; its students are those holding a
 * place in it.
 *
 * @phpstan-import-type Offering from CourseTeaching
 *
 * @phpstan-type Course array{offering: Offering, period: array{id: string, name: string, starts_on: string, ends_on: string}, division_id: string}
 */
final class Courses
{
    public function __construct(
        private readonly CourseTeaching $teaching,
        private readonly AcademicCalendar $calendar,
        private readonly SchoolDirectory $school,
        private readonly Access $access,
        private readonly Clock $clock,
    ) {}

    /**
     * The semesters of the year now, for a division.
     *
     * @return list<array{id: string, name: string, starts_on: string, ends_on: string}>
     */
    public function periods(string $divisionId): array
    {
        return $this->calendar->currentYear($this->clock->today())['terms'][$divisionId] ?? [];
    }

    /**
     * The semester now, or the next one, or the last.
     *
     * @return array{id: string, name: string, starts_on: string, ends_on: string}|null
     */
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

    /**
     * One course of a division, or not found.
     *
     * @return Course
     */
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

    /** Checks the division studies by programmes and the person may see Classroom there at all. */
    public function enter(User $who, string $divisionId): void
    {
        $at = ScopeRef::of(ScopeType::Division, $divisionId);
        if (! $this->access->allows($who, ClassroomPermission::View, $at)) {
            $this->access->check($who, ClassroomPermission::Teach, $at);
        }
        $division = $this->school->division($divisionId);
        if ($division === null || $division->profile->binding(ProgrammesSlot::Shape) !== StudyShape::Programmes) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * Whether the person runs this course: named as its lecturer, holding `classroom.teach`.
     *
     * @param  Course  $course
     */
    public function teaches(User $who, array $course): bool
    {
        return $this->access->allows($who, ClassroomPermission::Teach, ScopeRef::of(ScopeType::Division, $course['division_id']))
            && in_array($who->id, array_column($course['offering']['lecturers'], 'id'), true);
    }

    public function sees(User $who, string $divisionId): bool
    {
        return $this->access->allows($who, ClassroomPermission::View, ScopeRef::of(ScopeType::Division, $divisionId));
    }

    /**
     * A course the person may open: they run it, or see the division's courses.
     *
     * @return Course
     */
    public function open(User $who, string $divisionId, string $offeringId): array
    {
        $this->enter($who, $divisionId);
        $course = $this->find($divisionId, $offeringId);
        if (! $this->teaches($who, $course) && ! $this->sees($who, $divisionId)) {
            throw new NotFoundHttpException;
        }

        return $course;
    }

    /**
     * A course the person runs, or refused.
     *
     * @return Course
     */
    public function run(User $who, string $divisionId, string $offeringId): array
    {
        $course = $this->open($who, $divisionId, $offeringId);

        return $this->teaches($who, $course) ? $course : throw new AccessDeniedHttpException;
    }

    /**
     * The student whose account this is, or null.
     *
     * @return array{learner_id: string, division_id: string}|null
     */
    public function student(User $who): ?array
    {
        return $this->teaching->studentOf($who->id);
    }

    /** Whether a learner holds a place in an offering in its semester. */
    public function holds(string $learnerId, string $offeringId, string $periodId): bool
    {
        return in_array($offeringId, $this->teaching->offeringsOf($learnerId, $periodId), true);
    }

    /**
     * The students holding a place in an offering.
     *
     * @return list<array{learner_id: string, name: string, number: string, user_id: string|null, phone: string|null}>
     */
    public function students(string $offeringId): array
    {
        return $this->teaching->students($offeringId);
    }

    /**
     * The offerings of a division's semester.
     *
     * @return list<Offering>
     */
    public function offerings(string $divisionId, string $periodId): array
    {
        return $this->teaching->offerings($divisionId, $periodId);
    }
}
