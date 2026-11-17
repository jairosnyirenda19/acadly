<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The invitation email: who invites, and the link that sets the first password. */
final class InvitationMail extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $school,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = $notifiable instanceof User ? ($notifiable->email ?? '') : '';
        $url = rtrim(config()->string('acadly.frontend_url'), '/')
            .'/reset-password?invite=1&token='.urlencode($this->token)
            .'&email='.urlencode($email);

        return (new MailMessage)
            ->subject("{$this->school}: staff account")
            ->line("{$this->school} has made a staff account for this email address.")
            ->action('Set a password', $url)
            ->line('The link expires in 7 days. If the account was not expected, no action is required.');
    }
}
