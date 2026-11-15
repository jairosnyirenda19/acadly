<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Contracts;

use Acadly\Kernel\Audit\AuditAction;

enum AcademicsAudit: string implements AuditAction
{
    case YearSaved = 'academics.year_saved';
    case ClassesSaved = 'academics.classes_saved';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::YearSaved => 'Set an academic year and its terms.',
            self::ClassesSaved => 'Set the classes of a level at a campus.',
        };
    }

    public function requiresReason(): bool
    {
        return false;
    }
}
