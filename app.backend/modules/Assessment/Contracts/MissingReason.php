<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Contracts;

/** Why a learner has no mark. Absent is unexcused; excused and exempt are always left out of a term grade. */
enum MissingReason: string
{
    case Absent = 'absent';
    case Excused = 'excused';
    case Exempt = 'exempt';

    public function label(): string
    {
        return match ($this) {
            self::Absent => 'Absent',
            self::Excused => 'Excused',
            self::Exempt => 'Exempt',
        };
    }

    public function isExcused(): bool
    {
        return $this !== self::Absent;
    }

    /** @return list<array{value: string, label: string}> */
    public static function choices(): array
    {
        return array_map(fn (self $r): array => ['value' => $r->value, 'label' => $r->label()], self::cases());
    }
}
