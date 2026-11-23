<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Contracts;

use Acadly\Kernel\Audit\AuditAction;

/** What Classroom writes to the audit trail (D-104). */
enum ClassroomAudit: string implements AuditAction
{
    case ResourceAdded = 'classroom.resource_added';
    case ResourceRemoved = 'classroom.resource_removed';
    case Announced = 'classroom.announced';
    case ShareSet = 'classroom.share_set';
    case TaskSaved = 'classroom.task_saved';
    case HandedIn = 'classroom.handed_in';
    case Withdrawn = 'classroom.withdrawn';
    case Marked = 'classroom.marked';
    case MarkChanged = 'classroom.mark_changed';
    case Released = 'classroom.released';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::ResourceAdded => 'Added a resource to a course.',
            self::ResourceRemoved => 'Removed a resource from a course.',
            self::Announced => 'Posted an announcement to a course.',
            self::ShareSet => 'Set the continuous-assessment share of a course.',
            self::TaskSaved => 'Saved an assignment or exam paper.',
            self::HandedIn => 'Handed in work.',
            self::Withdrawn => 'Withdrew a hand-in.',
            self::Marked => 'Marked a hand-in.',
            self::MarkChanged => 'Changed a released mark.',
            self::Released => 'Released the marks of an assignment or exam paper.',
        };
    }

    public function requiresReason(): bool
    {
        return $this === self::MarkChanged;
    }
}
