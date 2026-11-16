<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\MessageSettings;
use Acadly\Kernel\Notify\Recipient;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * Email, through the mail server set when the school was installed. Plain
 * text: the words and the link, from the name and with the reply address the
 * school chose.
 */
final class EmailChannel implements ChannelAdapter
{
    public function __construct(private readonly MessageSettings $settings) {}

    public function channel(): Channel
    {
        return Channel::Email;
    }

    public function send(RenderedNotice $notice, Recipient $to): ProviderReceipt
    {
        if ($to->email === null) {
            return ProviderReceipt::failed('no_address')->from('mail');
        }

        $route = $this->settings->emailRoute();
        $text = $notice->body.($notice->actionUrl !== null ? "\n\n".$notice->actionUrl : '');

        Mail::raw($text, function (Message $message) use ($notice, $to, $route): void {
            $message->to((string) $to->email, $to->name)->subject($notice->subject);

            if ($route['from_name'] !== null) {
                $message->from(config()->string('mail.from.address'), $route['from_name']);
            }

            if ($route['reply_to'] !== null) {
                $message->replyTo($route['reply_to']);
            }
        });

        return ProviderReceipt::sent()->from('mail');
    }
}
