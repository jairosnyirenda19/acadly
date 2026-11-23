<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Contracts;

use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Documents\DocumentKind;
use Acadly\Modules\Classroom\Application\ClassroomOwners;

/**
 * The documents of a course page (D-104): resources, the files of an
 * assignment, the students' hand-ins and the lecturer's marked files. The
 * owner record says who else may open them: the course's students, and the
 * student whose hand-in or feedback it is.
 */
enum ClassroomDocument: string implements DocumentKind
{
    case Resource = 'classroom.resource';
    case Brief = 'classroom.brief';
    case HandIn = 'classroom.hand_in';
    case Feedback = 'classroom.feedback';

    /** What a college accepts: PDF, Word, Excel, PowerPoint, JPEG and PNG. */
    public const array TYPES = [
        'pdf' => 'application/pdf',
        'word' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'word_old' => 'application/msword',
        'excel' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'excel_old' => 'application/vnd.ms-excel',
        'powerpoint' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'powerpoint_old' => 'application/vnd.ms-powerpoint',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public const int MAX_MB = 20;

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Resource => 'Course resource',
            self::Brief => 'Assignment file',
            self::HandIn => 'Hand-in',
            self::Feedback => 'Marked file',
        };
    }

    public function ownerType(): string
    {
        return match ($this) {
            self::Resource => 'classroom_resource',
            self::Brief => 'classroom_task',
            self::HandIn => 'classroom_hand_in',
            self::Feedback => 'classroom_mark',
        };
    }

    public function owner(): string
    {
        return ClassroomOwners::for($this);
    }

    public function accepts(): array
    {
        return array_values(self::TYPES);
    }

    public function maxBytes(): int
    {
        return self::MAX_MB * 1024 * 1024;
    }

    public function classification(): FieldClass
    {
        return $this === self::HandIn || $this === self::Feedback ? FieldClass::Personal : FieldClass::Plain;
    }

    public function readPermission(): Permission
    {
        return ClassroomPermission::View;
    }

    public function writePermission(): Permission
    {
        return ClassroomPermission::Teach;
    }

    public function essential(): bool
    {
        return $this === self::HandIn || $this === self::Feedback;
    }

    public function isPublic(): bool
    {
        return false;
    }
}
