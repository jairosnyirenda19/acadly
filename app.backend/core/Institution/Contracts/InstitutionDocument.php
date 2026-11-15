<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Contracts;

use Acadly\Core\Institution\Application\SchoolOwner;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Documents\DocumentKind;

/** The documents the school itself owns. */
enum InstitutionDocument: string implements DocumentKind
{
    /** The logo: on the sign-in page, report cards and letters. Earlier logos stay as versions. */
    case Logo = 'institution.logo';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return 'School logo';
    }

    public function ownerType(): string
    {
        return 'institution';
    }

    public function owner(): string
    {
        return SchoolOwner::class;
    }

    public function accepts(): array
    {
        return ['image/png', 'image/jpeg'];
    }

    public function maxBytes(): int
    {
        return 1024 * 1024;
    }

    public function classification(): FieldClass
    {
        return FieldClass::Plain;
    }

    public function readPermission(): Permission
    {
        return InstitutionPermission::Manage;
    }

    public function writePermission(): Permission
    {
        return InstitutionPermission::Manage;
    }

    public function essential(): bool
    {
        return true;
    }

    public function isPublic(): bool
    {
        return true;
    }
}
