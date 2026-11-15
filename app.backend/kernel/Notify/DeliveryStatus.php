<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

/** Where one delivery stands (04 §10). */
enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
    case Unaddressed = 'unaddressed';
}
