<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Notifications;

use Acadly\Kernel\Notify\PartyResolver;
use Acadly\Kernel\Notify\Recipient;
use Acadly\Kernel\Notify\Topic;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;

/** The students holding a place in the course, at send time. */
final class CourseStudents implements PartyResolver
{
    public function __construct(private readonly CourseTeaching $teaching) {}

    public function resolve(Topic $topic): array
    {
        if (! $topic instanceof CourseAnnounced) {
            return [];
        }
        $out = [];
        foreach ($this->teaching->students($topic->offeringId) as $s) {
            $out[] = new Recipient($s['name'], $s['user_id'], null, $s['phone']);
        }

        return $out;
    }
}
