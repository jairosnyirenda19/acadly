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
        };
    }

    public function requiresReason(): bool
    {
        return false;
    }
}
