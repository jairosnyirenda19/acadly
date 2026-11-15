<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

/**
 * A way a notice reaches a person (04 §8). Push and WhatsApp join this list
 * when their adapters are built; a channel is declared only when something
 * sends through it.
 */
enum Channel: string
{
    case InApp = 'in_app';
    case Email = 'email';
    case Sms = 'sms';

    /** A channel that costs money per message, metered and capped by the licence. */
    public function isPaid(): bool
    {
        return $this === self::Sms;
    }
}
