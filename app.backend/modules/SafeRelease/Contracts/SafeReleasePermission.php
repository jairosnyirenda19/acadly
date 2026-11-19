<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Contracts;

use Acadly\Kernel\Access\Permission;

/** Safe pick-up's permissions (D-094). */
enum SafeReleasePermission: string implements Permission
{
    case Release = 'saferelease.release';
    case Manage = 'saferelease.manage';
    case View = 'saferelease.view';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Release => 'Release children at pick-up',
            self::Manage => 'Keep who may collect',
            self::View => 'See pick-up',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Release => 'Release a child to someone the family named, or record that someone not on the list was refused.',
            self::Manage => 'Add or end the people who may collect a child, and record a family member who must not collect, with a reason.',
            self::View => 'See who collected each child and who may collect, without releasing anyone.',
        };
    }

    public function isSight(): bool
    {
        return $this === self::View;
    }
}
