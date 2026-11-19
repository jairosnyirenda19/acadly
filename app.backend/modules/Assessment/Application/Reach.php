<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Boundary;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Assessment\Contracts\AssessmentModel;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Contracts\AssessmentSlot;
use Acadly\Modules\Assessment\Domain\Assessment;

/**
 * Who may see a class's assessments, and whose an assessment is. Anyone who
 * holds an assessment permission at the class sees it; anyone else is told
 * "not found". An assessment is a teacher's own when they set it or are given
 * its subject in its class.
 *
 * @phpstan-type ClassRow array{id: string, name: string, year_id: string, division_id: string, campus_id: string, level_code: string}
 */
final class Reach
{
    /** @var array<string, list<ClassRow>> */
    private array $classes = [];

    /** @var array<string, AssessmentModel> */
    private array $models = [];

    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly Teaching $teaching,
        private readonly SchoolDirectory $school,
    ) {}

    public function sees(User $who, string $classId): bool
    {
        foreach (AssessmentPermission::cases() as $permission) {
            if ($this->may($who, $permission, $classId)) {
                return true;
            }
        }

        return false;
    }

    public function may(User $who, AssessmentPermission $permission, string $classId): bool
    {
        return $this->access->allows($who, $permission, ScopeRef::of(ScopeType::ClassGroup, $classId));
    }

    public function owns(User $who, Assessment $assessment): bool
    {
        return $assessment->created_by === $who->id || $this->teaching->teaches($who->id, $assessment->class_id, $assessment->subject_id);
    }

    /**
     * The classes of this year the viewer sees, in levels that take marks.
     *
     * @return list<ClassRow>
     */
    public function classes(User $who): array
    {
        if (! isset($this->classes[Boundary::cacheKey($who)])) {
            $year = $this->calendar->currentYear();
            $this->classes[Boundary::cacheKey($who)] = $year === null ? [] : array_values(array_filter($this->calendar->classesInYear($year['id']),
                fn (array $c): bool => $this->takesMarks($c['division_id']) && $this->sees($who, $c['id'])));
        }

        return $this->classes[Boundary::cacheKey($who)];
    }

    public function model(string $divisionId): AssessmentModel
    {
        if (! isset($this->models[$divisionId])) {
            $model = $this->school->division($divisionId)?->profile->binding(AssessmentSlot::Model);
            $this->models[$divisionId] = $model instanceof AssessmentModel ? $model : AssessmentModel::Marks;
        }

        return $this->models[$divisionId];
    }

    public function takesMarks(string $divisionId): bool
    {
        return $this->model($divisionId)->takesMarks();
    }

    /** The name of a level as its profile says it: 'Form 3'. */
    public function levelLabel(string $divisionId, string $levelCode): string
    {
        return $this->school->division($divisionId)?->profile->levelLabel($levelCode) ?? $levelCode;
    }
}
