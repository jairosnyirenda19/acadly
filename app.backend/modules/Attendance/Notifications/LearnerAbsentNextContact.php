<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Notifications;

use Acadly\Kernel\Notify\Notice;

/**
 * The same notice, sent by a member of staff to the next contact when the
 * first could not be reached.
 */
final readonly class LearnerAbsentNextContact extends AbsenceTopic
{
    public static function notice(): Notice
    {
        return self::words(Notice::make('attendance.learner_absent_next')->named('Learner absent, told to the next contact'))
            ->toParty(NextContact::class);
    }
}
