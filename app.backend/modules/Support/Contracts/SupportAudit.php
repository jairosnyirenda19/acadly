<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Contracts;

use Acadly\Kernel\Audit\AuditAction;

enum SupportAudit: string implements AuditAction
{
    case PlanOpened = 'support.plan_opened';
    case PlanChanged = 'support.plan_changed';
    case Reviewed = 'support.reviewed';
    case SetAside = 'support.set_aside';
    case RulesSaved = 'support.rules_saved';
    case FamilyPartSaved = 'support.family_part_saved';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::PlanOpened => 'Opened a support plan for a learner.',
            self::PlanChanged => 'Added a goal or an action to a support plan.',
            self::Reviewed => 'Reviewed a support plan.',
            self::SetAside => 'Set a learner\'s signals aside.',
            self::RulesSaved => 'Saved the support rules.',
            self::FamilyPartSaved => 'Saved the part of a support plan written for the family.',
        };
    }

    public function requiresReason(): bool
    {
        return $this === self::SetAside;
    }
}
