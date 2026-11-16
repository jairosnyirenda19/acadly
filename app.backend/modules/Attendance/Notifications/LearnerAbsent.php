<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Notifications;

use Acadly\Core\Enrolment\Contracts\LearnerContacts;
use Acadly\Kernel\Notify\Notice;

/**
 * Sent when a register for today is submitted, once for each learner marked
 * absent, to the main contact (or every contact, as the school chose), by the
 * channel each contact chose. A register for an earlier day sends nothing.
 */
final readonly class LearnerAbsent extends AbsenceTopic
{
    public static function notice(): Notice
    {
        return self::words(Notice::make('attendance.learner_absent')->named('Learner absent'))
            ->toParty(LearnerContacts::class);
    }
}
