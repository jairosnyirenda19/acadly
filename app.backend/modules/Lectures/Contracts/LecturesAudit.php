<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Contracts;

use Acadly\Kernel\Audit\AuditAction;

/** What Lectures writes to the audit trail (D-102). */
enum LecturesAudit: string implements AuditAction
{
    case RoomSaved = 'lectures.room_saved';
    case AreaSaved = 'lectures.area_saved';
    case SlotSaved = 'lectures.slot_saved';
    case SlotRemoved = 'lectures.slot_removed';
    case Published = 'lectures.published';
    case Away = 'lectures.away';
    case CoverAsked = 'lectures.cover_asked';
    case CoverAnswered = 'lectures.cover_answered';
    case CoverNamed = 'lectures.cover_named';
    case Cancelled = 'lectures.cancelled';
    case Moved = 'lectures.moved';
    case Opened = 'lectures.opened';
    case Closed = 'lectures.closed';
    case HandMark = 'lectures.hand_mark';
    case PhoneSet = 'lectures.phone_set';
    case PhoneReset = 'lectures.phone_reset';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::RoomSaved => 'Saved a room.',
            self::AreaSaved => 'Saved the campus area and the scanning time.',
            self::SlotSaved => 'Saved a lecture on the timetable.',
            self::SlotRemoved => 'Removed a lecture from the timetable.',
            self::Published => 'Published the lecture timetable.',
            self::Away => 'Recorded that a lecturer cannot come.',
            self::CoverAsked => 'Asked a colleague to cover a lecture.',
            self::CoverAnswered => 'Answered a request to cover a lecture.',
            self::CoverNamed => 'Named a colleague to cover a lecture.',
            self::Cancelled => 'Cancelled a lecture.',
            self::Moved => 'Moved a lecture.',
            self::Opened => 'Opened attendance at a lecture.',
            self::Closed => 'Closed attendance at a lecture.',
            self::HandMark => 'Marked a student at a lecture by hand.',
            self::PhoneSet => 'Set an attendance phone.',
            self::PhoneReset => 'Reset a student\'s attendance phone.',
        };
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::Away, self::Cancelled, self::Moved, self::HandMark, self::PhoneReset], true);
    }
}
