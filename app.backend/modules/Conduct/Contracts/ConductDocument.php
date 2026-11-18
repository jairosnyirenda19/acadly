<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Contracts;

use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Documents\DocumentKind;
use Acadly\Modules\Conduct\Application\CaseOwner;

/** The documents of a serious incident: statements and notes, kept confidential. */
enum ConductDocument: string implements DocumentKind
{
    case Statement = 'conduct.statement';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return 'Serious incident statement';
    }

    public function ownerType(): string
    {
        return 'conduct_case';
    }

    public function owner(): string
    {
        return CaseOwner::class;
    }

    public function accepts(): array
    {
        return ['application/pdf', 'image/jpeg', 'image/png'];
    }

    public function maxBytes(): int
    {
        return 20 * 1024 * 1024;
    }

    public function classification(): FieldClass
    {
        return FieldClass::Secret;
    }

    public function readPermission(): Permission
    {
        return ConductPermission::FollowUp;
    }

    public function writePermission(): Permission
    {
        return ConductPermission::FollowUp;
    }

    public function essential(): bool
    {
        return true;
    }

    public function isPublic(): bool
    {
        return false;
    }
}
