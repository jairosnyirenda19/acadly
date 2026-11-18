<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Domain;

/** How a payment was made. Every method but cash carries the provider's or bank's reference. */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case AirtelMoney = 'airtel_money';
    case Mpamba = 'mpamba';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Bank => 'Bank deposit',
            self::AirtelMoney => 'Airtel Money',
            self::Mpamba => 'TNM Mpamba',
            self::Cheque => 'Cheque',
        };
    }

    public function needsReference(): bool
    {
        return $this !== self::Cash;
    }
}
