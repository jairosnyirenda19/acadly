<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Recipient;

/**
 * Sends one rendered notice to one person on one channel (04 §8). An adapter
 * returns a failed receipt for a refusal it understands, and throws only for
 * a fault worth retrying.
 */
interface ChannelAdapter
{
    public function channel(): Channel;

    public function send(RenderedNotice $notice, Recipient $to): ProviderReceipt;
}
