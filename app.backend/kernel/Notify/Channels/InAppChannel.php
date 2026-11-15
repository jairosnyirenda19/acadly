<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Recipient;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;

/** The bell: a row the person sees when they next open Acadly. */
final class InAppChannel implements ChannelAdapter
{
    public function __construct(private readonly Clock $clock) {}

    public function channel(): Channel
    {
        return Channel::InApp;
    }

    public function send(RenderedNotice $notice, Recipient $to): ProviderReceipt
    {
        if ($to->userId === null) {
            return ProviderReceipt::failed('no_account');
        }

        DB::table('in_app_notifications')->insertOrIgnore([
            'id' => Ids::next(),
            'user_id' => $to->userId,
            'delivery_id' => $notice->deliveryId,
            'topic_key' => $notice->topicKey,
            'subject' => $notice->subject,
            'body' => $notice->body,
            'action_url' => $notice->actionUrl,
            'created_at' => $this->clock->now(),
        ]);

        return ProviderReceipt::delivered();
    }
}
