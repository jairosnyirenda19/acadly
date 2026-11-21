<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Notifications;

use Acadly\Kernel\Notify\PartyResolver;
use Acadly\Kernel\Notify\Recipient;
use Acadly\Kernel\Notify\Topic;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;

/** The students holding a place in the course a lecture belongs to, at send time. */
final class RegisteredStudents implements PartyResolver
{
    public function __construct(private readonly CourseTeaching $teaching) {}

    public function resolve(Topic $topic): array
    {
        if (! $topic instanceof LectureChanged) {
            return [];
        }
        $out = [];
        foreach ($this->teaching->students($topic->offeringId) as $s) {
            $out[] = new Recipient($s['name'], $s['user_id'], null, $s['phone']);
        }

        return $out;
    }
}
