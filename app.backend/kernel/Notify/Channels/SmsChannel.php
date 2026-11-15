<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Recipient;

/** SMS: the short words, through the deployment's provider. */
final class SmsChannel implements ChannelAdapter
{
    public function __construct(private readonly SmsProvider $provider) {}

    public function channel(): Channel
    {
        return Channel::Sms;
    }

    public function send(RenderedNotice $notice, Recipient $to): ProviderReceipt
    {
        if ($to->phone === null) {
            return ProviderReceipt::failed('no_address');
        }

        return $this->provider->send($to->phone, $notice->short ?? $notice->subject);
    }
}
