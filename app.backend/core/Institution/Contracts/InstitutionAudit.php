<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Contracts;

use Acadly\Kernel\Audit\AuditAction;

enum InstitutionAudit: string implements AuditAction
{
    case DetailsSaved = 'institution.details_saved';
    case LogoReplaced = 'institution.logo_replaced';
    case LevelsSet = 'institution.levels_set';
    case CampusSaved = 'institution.campus_saved';
    case OfferingSet = 'institution.offering_set';
    case Opened = 'institution.opened';
    case LookSaved = 'institution.look_saved';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::DetailsSaved => 'Changed the school details.',
            self::LogoReplaced => 'Replaced the school logo.',
            self::LevelsSet => 'Set the levels the school runs.',
            self::CampusSaved => 'Added or changed a campus.',
            self::OfferingSet => 'Changed where a level is taught.',
            self::Opened => 'Opened the school.',
            self::LookSaved => 'Changed the branding: the name shown, the logo, the colours.',
        };
    }

    public function requiresReason(): bool
    {
        return false;
    }
}
