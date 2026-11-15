<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Recipient;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/** Email, through the deployment's mailer. Plain text: the words and the link. */
final class EmailChannel implements ChannelAdapter
{
    public function channel(): Channel
    {
        return Channel::Email;
    }

    public function send(RenderedNotice $notice, Recipient $to): ProviderReceipt
    {
        if ($to->email === null) {
            return ProviderReceipt::failed('no_address');
        }

        $text = $notice->body.($notice->actionUrl !== null ? "\n\n".$notice->actionUrl : '');

        Mail::raw($text, function (Message $message) use ($notice, $to): void {
            $message->to((string) $to->email, $to->name)->subject($notice->subject);
        });

        return ProviderReceipt::sent();
    }
}
