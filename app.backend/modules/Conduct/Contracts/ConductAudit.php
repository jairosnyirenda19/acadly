<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Contracts;

use Acadly\Kernel\Audit\AuditAction;

enum ConductAudit: string implements AuditAction
{
    case Recorded = 'conduct.recorded';
    case Withdrawn = 'conduct.withdrawn';
    case NoteAdded = 'conduct.note_added';
    case OutcomeGiven = 'conduct.outcome_given';
    case KindsSaved = 'conduct.kinds_saved';
    case WatchSaved = 'conduct.watch_saved';
    case SuspensionEnded = 'conduct.suspension_ended';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::Recorded => 'Recorded an incident or a commendation.',
            self::Withdrawn => 'Withdrew a conduct entry.',
            self::NoteAdded => 'Added a note to a serious incident.',
            self::OutcomeGiven => 'Gave the outcome of a serious incident for a learner.',
            self::KindsSaved => 'Saved the kinds of incident and commendation.',
            self::WatchSaved => 'Saved the rules for learners to watch.',
            self::SuspensionEnded => 'Ended a suspension early.',
        };
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::Withdrawn, self::OutcomeGiven, self::SuspensionEnded], true);
    }
}
