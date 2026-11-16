<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Recipient;
use Illuminate\Contracts\Container\Container;

/** SMS: the short words, through the provider the school chose. The provider is read at each send. */
final class SmsChannel implements ChannelAdapter
{
    public function __construct(private readonly Container $container) {}

    public function channel(): Channel
    {
        return Channel::Sms;
    }

    public function send(RenderedNotice $notice, Recipient $to): ProviderReceipt
    {
        $provider = $this->container->make(SmsProvider::class);

        if ($to->phone === null) {
            return ProviderReceipt::failed('no_address')->from($provider->key());
        }

        return $provider->send($to->phone, $notice->short ?? $notice->subject)->from($provider->key());
    }
}
