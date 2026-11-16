<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Notifications;

use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Notice;

/** Marks were returned: the teacher who submitted them hears it, with the reason on the marks sheet. */
final readonly class MarksReturned extends MarksTopic
{
    public static function notice(): Notice
    {
        return Notice::make('assessment.returned')->named('Marks returned')
            ->category(AssessmentCategory::Assessment)
            ->facts('assessment', 'title', 'class', 'subject')
            ->examples(['title' => 'Weekly test 3', 'class' => 'Form 2 West', 'subject' => 'Biology'])
            ->subject('Marks returned: :subject, :title')
            ->line(':actor returned the marks of :subject, :title, for :class.')
            ->line('The reason is on the marks sheet. Correct the marks and submit them again.')
            ->action('Open the marks', '/marks/:assessment')
            ->toParty(TheSubmitter::class)
            ->channels(default: [Channel::InApp], allowed: [Channel::InApp, Channel::Email]);
    }
}
