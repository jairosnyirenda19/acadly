<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Notifications;

use Acadly\Core\Enrolment\Contracts\AboutLearner;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerContacts;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;
use Carbon\CarbonImmutable;

/**
 * An incident or a commendation, told to the family. It names this learner
 * only, never another learner in the same incident.
 */
final readonly class ConductRecorded implements AboutLearner, Topic
{
    public const string SUBJECT = 'conduct.entry_learner';

    public function __construct(
        public string $rowId,
        public string $learner,
        public string $classId,
        public string $kind,
        public string $headline,
        public string $day,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('conduct.recorded')->named('Conduct')
            ->category(ConductCategory::Conduct)
            ->facts('learner', 'what', 'day')
            ->examples(['learner' => 'Tiwonge Mwale', 'what' => 'a commendation for helping others', 'day' => '2 Nov'])
            ->subject(':learner: :what')
            ->line('On :day the school recorded :what for :learner.')
            ->line('To talk about it, please call the school on :school_phone.')
            ->short(':institution: on :day we recorded :what for :learner. Call :school_phone to talk about it.')
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms, Channel::Email])
            ->toParty(LearnerContacts::class);
    }

    public function learnerId(): string
    {
        return $this->learner;
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef(self::SUBJECT, $this->rowId);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $this->classId);
    }

    public function tokens(): array
    {
        return [
            'learner' => app(EnrolmentDirectory::class)->names([$this->learner])[$this->learner] ?? null,
            'what' => $this->headline,
            'day' => CarbonImmutable::parse($this->day)->format('j M'),
        ];
    }

    public function dedupeKey(): string
    {
        return $this->rowId;
    }

    public function toPayload(): array
    {
        return ['row' => $this->rowId, 'learner' => $this->learner, 'class' => $this->classId, 'kind' => $this->kind, 'headline' => $this->headline, 'day' => $this->day];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('row'), $text('learner'), $text('class'), $text('kind'), $text('headline'), $text('day'));
    }
}
