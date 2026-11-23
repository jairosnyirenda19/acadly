<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Application;

use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Classroom\Contracts\ClassroomDocument;

/** The owner of a course resource (D-104). */
final class ResourceOwner extends ClassroomOwners
{
    public function __construct(Courses $courses, Clock $clock)
    {
        parent::__construct($courses, $clock, ClassroomDocument::Resource);
    }
}
