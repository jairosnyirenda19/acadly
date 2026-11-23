<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Notifications;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;

/**
 * The registrar releases a semester's results (D-105). Sent once, by text
 * message, to every student with a released result in it.
 */
final readonly class ResultsReleased implements Topic
{
    public function __construct(
        public string $releaseId,
        public string $divisionId,
        public string $semester,
        public string $sitting,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('results.released')->named('Results released')
            ->category(ResultsCategory::Results)
            ->facts('semester')
            ->examples(['semester' => 'Semester 1'])
            ->subject('Results for :semester')
            ->line('Your results for :semester are released.')
            ->line('See them in the student portal, under Results.')
            ->short(':institution: your results for :semester are released. See the student portal.')
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms])
            ->toParty(ReleasedStudents::class);
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef('results.release', $this->releaseId);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::Division, $this->divisionId);
    }

    public function tokens(): array
    {
        return ['semester' => $this->semester];
    }

    public function dedupeKey(): string
    {
        return $this->releaseId;
    }

    public function toPayload(): array
    {
        return ['release' => $this->releaseId, 'division' => $this->divisionId, 'semester' => $this->semester, 'sitting' => $this->sitting];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('release'), $text('division'), $text('semester'), $text('sitting'));
    }
}
