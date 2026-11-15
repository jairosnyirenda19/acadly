<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

use Acadly\Core\Enrolment\Application\LearnerOwner;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Documents\DocumentKind;

/** The documents a learner record owns. */
enum EnrolmentDocument: string implements DocumentKind
{
    /** An optional passport-sized photo of the face (08, stage 3). Earlier photos stay as versions. */
    case Photo = 'enrolment.photo';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return 'Learner photo';
    }

    public function ownerType(): string
    {
        return 'learner';
    }

    public function owner(): string
    {
        return LearnerOwner::class;
    }

    public function accepts(): array
    {
        return ['image/jpeg', 'image/png'];
    }

    public function maxBytes(): int
    {
        return 2 * 1024 * 1024;
    }

    public function classification(): FieldClass
    {
        return FieldClass::Personal;
    }

    public function readPermission(): Permission
    {
        return EnrolmentPermission::View;
    }

    public function writePermission(): Permission
    {
        return EnrolmentPermission::Manage;
    }

    public function essential(): bool
    {
        return false;
    }

    public function isPublic(): bool
    {
        return false;
    }
}
