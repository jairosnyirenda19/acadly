<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Contracts;

use Acadly\Kernel\Audit\AuditAction;

enum AcademicsAudit: string implements AuditAction
{
    case YearSaved = 'academics.year_saved';
    case TermsSaved = 'academics.terms_saved';
    case ClassesSaved = 'academics.classes_saved';
    case SubjectsStarted = 'academics.subjects_started';
    case SubjectSaved = 'academics.subject_saved';
    case SubjectRetired = 'academics.subject_retired';
    case TeachingGiven = 'academics.teaching_given';
    case TeachingCopied = 'academics.teaching_copied';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::YearSaved => 'Set an academic year.',
            self::TermsSaved => 'Set the terms of a level for an academic year.',
            self::ClassesSaved => 'Set the classes of a level at a campus.',
            self::SubjectsStarted => 'Started the subjects of a level from its profile.',
            self::SubjectSaved => 'Added or changed a subject.',
            self::SubjectRetired => 'Retired a subject.',
            self::TeachingGiven => 'Gave a subject or a class to a teacher, or took it back.',
            self::TeachingCopied => 'Copied who teaches what from the year before.',
        };
    }

    public function requiresReason(): bool
    {
        return $this === self::SubjectRetired;
    }
}
