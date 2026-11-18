<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Contracts;

use Acadly\Kernel\Audit\AuditAction;

enum FeesAudit: string implements AuditAction
{
    case TermFeesSaved = 'fees.term_fees_saved';
    case TermBilled = 'fees.term_billed';
    case PaymentRecorded = 'fees.payment_recorded';
    case ReceiptCancelled = 'fees.receipt_cancelled';
    case Adjusted = 'fees.adjusted';
    case PlanAgreed = 'fees.plan_agreed';
    case TakersChanged = 'fees.takers_changed';
    case ShopSale = 'fees.shop_sale';
    case ShopListSaved = 'fees.shop_list_saved';
    case ItemsSaved = 'fees.items_saved';
    case DayClosed = 'fees.day_closed';
    case RemindersSent = 'fees.reminders_sent';
    case Exported = 'fees.exported';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::TermFeesSaved => 'Saved the fees of a term.',
            self::TermBilled => 'Billed the fees of a term to the learners.',
            self::PaymentRecorded => 'Recorded a fee payment and gave a receipt.',
            self::ReceiptCancelled => 'Cancelled a fee receipt.',
            self::Adjusted => 'Gave a discount, bursary or waiver on a learner\'s fees.',
            self::PlanAgreed => 'Agreed a different instalment plan with a family.',
            self::TakersChanged => 'Changed the optional fee items a learner takes.',
            self::ShopSale => 'Sold items from the school shop.',
            self::ShopListSaved => 'Saved the school shop price list.',
            self::ItemsSaved => 'Saved the fee items.',
            self::DayClosed => 'Closed a day of fee collection.',
            self::RemindersSent => 'Sent fee reminders to families.',
            self::Exported => 'Downloaded fee payments and bills.',
        };
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::ReceiptCancelled, self::Adjusted, self::PlanAgreed], true);
    }
}
