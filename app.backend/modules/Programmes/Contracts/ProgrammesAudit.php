<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Contracts;

use Acadly\Kernel\Audit\AuditAction;

/** What Programmes writes to the audit trail (D-097). */
enum ProgrammesAudit: string implements AuditAction
{
    case FacultiesSaved = 'programmes.faculties_saved';
    case DepartmentSaved = 'programmes.department_saved';
    case CourseSaved = 'programmes.course_saved';
    case ProgrammeSaved = 'programmes.programme_saved';
    case CurriculumDrafted = 'programmes.curriculum_drafted';
    case CurriculumSaved = 'programmes.curriculum_saved';
    case CurriculumReleased = 'programmes.curriculum_released';
    case OfferingsPrepared = 'programmes.offerings_prepared';
    case OfferingSaved = 'programmes.offering_saved';
    case StudentPlaced = 'programmes.student_placed';
    case WindowSaved = 'programmes.window_saved';
    case SemesterStarted = 'programmes.semester_started';
    case RegistrationOpened = 'programmes.registration_opened';
    case CoursesChosen = 'programmes.courses_chosen';
    case FeesAllowed = 'programmes.fees_allowed';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::FacultiesSaved => 'Saved the faculties.',
            self::DepartmentSaved => 'Saved a department.',
            self::CourseSaved => 'Saved a course in the catalogue.',
            self::ProgrammeSaved => 'Saved a programme.',
            self::CurriculumDrafted => 'Started a new curriculum version.',
            self::CurriculumSaved => 'Saved a draft curriculum.',
            self::CurriculumReleased => 'Released a curriculum version.',
            self::OfferingsPrepared => 'Prepared a semester\'s offerings.',
            self::OfferingSaved => 'Saved an offering and its lecturers.',
            self::StudentPlaced => 'Placed a student in a programme.',
            self::WindowSaved => 'Saved the registration dates of a semester.',
            self::SemesterStarted => 'Started registration for a semester.',
            self::RegistrationOpened => 'Opened a semester registration for a student.',
            self::CoursesChosen => 'Chose the courses of a semester registration.',
            self::FeesAllowed => 'Allowed a registration without the fees needed.',
        };
    }

    public function requiresReason(): bool
    {
        return $this === self::FeesAllowed;
    }
}
