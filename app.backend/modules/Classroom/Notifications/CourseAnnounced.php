<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Notifications;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;

/**
 * A lecturer posts an announcement to a course and asks for a text message
 * as well (D-104). Sent once, to every student holding a place in the course.
 */
final readonly class CourseAnnounced implements Topic
{
    public function __construct(
        public string $announcementId,
        public string $offeringId,
        public string $divisionId,
        public string $course,
        public string $title,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('classroom.announced')->named('A course announcement')
            ->category(ClassroomCategory::Classroom)
            ->facts('course', 'title')
            ->examples(['course' => 'ACC 101', 'title' => 'Tutorial moved to B14 this week'])
            ->subject(':course: :title')
            ->line(':course: :title.')
            ->line('Read it on the course page in the student portal.')
            ->short(':institution: :course: :title. See the student portal.')
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms])
            ->toParty(CourseStudents::class);
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef('classroom.announcement', $this->announcementId);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::Division, $this->divisionId);
    }

    public function tokens(): array
    {
        return ['course' => $this->course, 'title' => $this->title];
    }

    public function dedupeKey(): string
    {
        return $this->announcementId;
    }

    public function toPayload(): array
    {
        return ['announcement' => $this->announcementId, 'offering' => $this->offeringId, 'division' => $this->divisionId, 'course' => $this->course, 'title' => $this->title];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('announcement'), $text('offering'), $text('division'), $text('course'), $text('title'));
    }
}
