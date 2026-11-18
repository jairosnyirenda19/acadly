<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Domain;

/** Less to pay on one charge. Each needs a reason. */
enum AdjustmentKind: string
{
    case Discount = 'discount';
    case Bursary = 'bursary';
    case Waiver = 'waiver';
    case WriteOff = 'write_off';

    public function label(): string
    {
        return match ($this) {
            self::Discount => 'Discount',
            self::Bursary => 'Bursary',
            self::Waiver => 'Waiver',
            self::WriteOff => 'Written off',
        };
    }
}
