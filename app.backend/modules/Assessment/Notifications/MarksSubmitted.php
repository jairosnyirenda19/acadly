<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Notifications;

use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Notice;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;

/** Marks were submitted: the desk of those who lock or return them hears it. */
final readonly class MarksSubmitted extends MarksTopic
{
    public static function notice(): Notice
    {
        return Notice::make('assessment.submitted')->named('Marks submitted')
            ->category(AssessmentCategory::Assessment)
            ->facts('assessment', 'title', 'class', 'subject')
            ->examples(['title' => 'Mid-term test', 'class' => 'Form 3', 'subject' => 'Biology'])
            ->subject('Marks submitted: :subject, :title')
            ->line(':actor submitted the marks of :subject, :title, for :class.')
            ->line('Check them, then lock them or return them to the teacher.')
            ->action('Open the marks', '/marks/:assessment')
            ->toDesk()
            ->actedOnWith(AssessmentPermission::Lock)
            ->channels(default: [Channel::InApp], allowed: [Channel::InApp, Channel::Email]);
    }
}
