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
 * A colleague is asked to cover a lecture, or named to cover it (D-102).
 * Sent to that colleague only.
 */
final readonly class CoverAsked implements Topic
{
    public function __construct(
        public string $changeId,
        public string $userId,
        public string $divisionId,
        public string $course,
        public string $when,
        public bool $named,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('lectures.cover')->named('Cover for a lecture')
            ->category(LecturesCategory::Lectures)
            ->facts('course', 'when', 'ask')
            ->examples(['course' => 'ACC 101 Lecture, B12', 'when' => 'Thu 26 Nov, 10:00 to 12:00', 'ask' => 'You are asked to cover it. Accept or decline on My day.'])
            ->subject('Cover: :course, :when')
            ->line(':course, :when.')
            ->line(':ask')
            ->short(':institution: cover for :course, :when. :ask')
            ->action('Open My day', '/my-day')
            ->channels(default: [Channel::InApp], allowed: [Channel::InApp, Channel::Email, Channel::Sms])
            ->toParty(TheColleague::class);
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
        return ['course' => $this->course, 'when' => $this->when,
            'ask' => $this->named ? 'You are named to cover it.' : 'You are asked to cover it. Accept or decline on My day.'];
    }

    public function dedupeKey(): string
    {
        return $this->changeId;
    }

    public function toPayload(): array
    {
        return ['change' => $this->changeId, 'user' => $this->userId, 'division' => $this->divisionId, 'course' => $this->course, 'when' => $this->when, 'named' => $this->named];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('change'), $text('user'), $text('division'), $text('course'), $text('when'), (bool) ($payload['named'] ?? false));
    }
}
