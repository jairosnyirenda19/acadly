<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Notifications;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;

/**
 * A lecture was cancelled, moved or covered, or the published timetable of
 * a course changed (D-102). Sent once a change, to every student holding a
 * place in the course, by text message.
 */
final readonly class LectureChanged implements Topic
{
    public function __construct(
        public string $changeId,
        public string $offeringId,
        public string $divisionId,
        public string $course,
        public string $when,
        public string $what,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('lectures.changed')->named('A lecture changed')
            ->category(LecturesCategory::Lectures)
            ->facts('course', 'when', 'what')
            ->examples(['course' => 'ACC 101 Lecture', 'when' => 'Thu 26 Nov, 10:00', 'what' => 'is moved to Fri 27 Nov, 14:00, HALL A'])
            ->subject(':course :what')
            ->line(':course on :when :what.')
            ->line('See This week in the student portal.')
            ->short(':institution: :course on :when :what.')
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms])
            ->toParty(RegisteredStudents::class);
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef('lectures.change', $this->changeId);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::Division, $this->divisionId);
    }

    public function tokens(): array
    {
        return ['course' => $this->course, 'when' => $this->when, 'what' => $this->what];
    }

    public function dedupeKey(): string
    {
        return $this->changeId;
    }

    public function toPayload(): array
    {
        return ['change' => $this->changeId, 'offering' => $this->offeringId, 'division' => $this->divisionId, 'course' => $this->course, 'when' => $this->when, 'what' => $this->what];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('change'), $text('offering'), $text('division'), $text('course'), $text('when'), $text('what'));
    }
}
