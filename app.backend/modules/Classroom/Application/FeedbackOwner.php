<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Application;

use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Classroom\Contracts\ClassroomDocument;

/** The owner of a marked file (D-104). */
final class FeedbackOwner extends ClassroomOwners
{
    public function __construct(Courses $courses, Clock $clock)
    {
        parent::__construct($courses, $clock, ClassroomDocument::Feedback);
    }
}
