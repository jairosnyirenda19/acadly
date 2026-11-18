<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

use Acadly\Core\Enrolment\Application\LearnerOwner;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Documents\DocumentKind;

/**
 * The documents a learner record owns. The school names its own document
 * types (D-090); each type keeps its documents as one of the three record
 * kinds, by who may open them.
 */
enum EnrolmentDocument: string implements DocumentKind
{
    /** An optional passport-sized photo of the face (08, stage 3). Earlier photos stay as versions. */
    case Photo = 'enrolment.photo';

    /** Open to staff who see the learner. */
    case Record = 'enrolment.record';

    /** Open to the people who keep learner records. */
    case RecordOffice = 'enrolment.record_office';

    /** Open only with the confidential permission. Every opening is recorded. */
    case RecordConfidential = 'enrolment.record_confidential';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Photo => 'Learner photo',
            self::Record => 'Learner document',
            self::RecordOffice => 'Learner document, office only',
            self::RecordConfidential => 'Confidential learner document',
        };
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
        return $this === self::Photo ? ['image/jpeg', 'image/png'] : ['application/pdf', 'image/jpeg', 'image/png'];
    }

    public function maxBytes(): int
    {
        return $this === self::Photo ? 2 * 1024 * 1024 : 20 * 1024 * 1024;
    }

    public function classification(): FieldClass
    {
        return $this === self::RecordConfidential ? FieldClass::Secret : FieldClass::Personal;
    }

    public function readPermission(): Permission
    {
        return match ($this) {
            self::Photo, self::Record => EnrolmentPermission::View,
            self::RecordOffice => EnrolmentPermission::Manage,
            self::RecordConfidential => EnrolmentPermission::Confidential,
        };
    }

    public function writePermission(): Permission
    {
        return $this === self::RecordConfidential ? EnrolmentPermission::Confidential : EnrolmentPermission::Manage;
    }

    /** Records the school must keep, such as a birth certificate, are kept even when storage is full. */
    public function essential(): bool
    {
        return $this === self::RecordOffice || $this === self::RecordConfidential;
    }

    public function isPublic(): bool
    {
        return false;
    }
}
