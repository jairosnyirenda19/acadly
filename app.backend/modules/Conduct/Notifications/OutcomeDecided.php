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

/** The outcome of a serious incident for one learner, told to the family. */
final readonly class OutcomeDecided implements AboutLearner, Topic
{
    public const string SUBJECT = 'conduct.outcome';

    public function __construct(
        public string $outcomeId,
        public string $learner,
        public string $classId,
        public string $outcome,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('conduct.outcome_decided')->named('Serious incident decided')
            ->category(ConductCategory::Conduct)
            ->facts('learner', 'outcome')
            ->examples(['learner' => 'Tiwonge Mwale', 'outcome' => 'Suspension: 3 school days from 19 Nov'])
            ->subject('A decision about :learner')
            ->line('The school has decided the serious incident involving :learner: :outcome.')
            ->line('Please call the school on :school_phone to arrange a meeting.')
            ->short(':institution: decision about :learner: :outcome. Please call :school_phone.')
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms, Channel::Email])
            ->toParty(LearnerContacts::class);
    }

    public function learnerId(): string
    {
        return $this->learner;
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef(self::SUBJECT, $this->outcomeId);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $this->classId);
    }

    public function tokens(): array
    {
        return [
            'learner' => app(EnrolmentDirectory::class)->names([$this->learner])[$this->learner] ?? null,
            'outcome' => $this->outcome,
        ];
    }

    public function dedupeKey(): string
    {
        return $this->outcomeId;
    }

    public function toPayload(): array
    {
        return ['outcome_id' => $this->outcomeId, 'learner' => $this->learner, 'class' => $this->classId, 'outcome' => $this->outcome];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('outcome_id'), $text('learner'), $text('class'), $text('outcome'));
    }
}
