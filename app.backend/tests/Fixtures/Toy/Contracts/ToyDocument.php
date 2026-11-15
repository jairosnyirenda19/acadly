<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy\Contracts;

use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Documents\DocumentKind;
use Tests\Fixtures\Toy\ErrandOwner;

/** The kinds of document an errand keeps: a receipt (personal) and a photo (plain, small, not essential). */
enum ToyDocument: string implements DocumentKind
{
    case Receipt = 'toy.receipt';
    case Photo = 'toy.photo';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Receipt',
            self::Photo => 'Photo',
        };
    }

    public function ownerType(): string
    {
        return 'toy_errand';
    }

    public function owner(): string
    {
        return ErrandOwner::class;
    }

    public function accepts(): array
    {
        return match ($this) {
            self::Receipt => ['application/pdf', 'image/png', 'image/jpeg'],
            self::Photo => ['image/png', 'image/jpeg'],
        };
    }

    public function maxBytes(): int
    {
        return match ($this) {
            self::Receipt => 5 * 1024 * 1024,
            self::Photo => 1024,
        };
    }

    public function classification(): FieldClass
    {
        return match ($this) {
            self::Receipt => FieldClass::Personal,
            self::Photo => FieldClass::Plain,
        };
    }

    public function readPermission(): Permission
    {
        return ToyPermission::Submit;
    }

    public function writePermission(): Permission
    {
        return ToyPermission::Submit;
    }

    public function essential(): bool
    {
        return $this === self::Receipt;
    }

    public function isPublic(): bool
    {
        return false;
    }
}
