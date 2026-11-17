<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Contracts;

use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Documents\AlsoReadWith;
use Acadly\Kernel\Documents\DocumentKind;
use Acadly\Modules\Assessment\Application\AssessmentOwner;

/** The documents Assessment keeps (the approved papers design). */
enum AssessmentDocument: string implements AlsoReadWith, DocumentKind
{
    /** One page of a learner's marked paper, photographed or scanned. Optional. */
    case Paper = 'assessment.paper';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return 'Marked paper';
    }

    public function ownerType(): string
    {
        return 'assessment';
    }

    public function owner(): string
    {
        return AssessmentOwner::class;
    }

    public function accepts(): array
    {
        return ['image/jpeg', 'image/png', 'application/pdf'];
    }

    public function maxBytes(): int
    {
        return 10 * 1024 * 1024;
    }

    public function classification(): FieldClass
    {
        return FieldClass::Personal;
    }

    public function readPermission(): Permission
    {
        return AssessmentPermission::View;
    }

    public function alsoReadWith(): array
    {
        return [AssessmentPermission::Enter, AssessmentPermission::Lock];
    }

    public function writePermission(): Permission
    {
        return AssessmentPermission::Enter;
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
