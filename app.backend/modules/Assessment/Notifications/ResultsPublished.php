<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Notifications;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\AboutLearner;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerContacts;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;

/**
 * A class's term results were published (D-080): each learner's family hears
 * that the report card is ready. Sent again when a correction is published,
 * once per version.
 */
final readonly class ResultsPublished implements AboutLearner, Topic
{
    public function __construct(
        public string $publicationId,
        public string $learner,
        public string $classId,
        public string $period,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('assessment.results_published')->named('Results published')
            ->category(AssessmentCategory::Assessment)
            ->facts('learner', 'class', 'period')
            ->examples(['learner' => 'Madalitso Kalua', 'class' => 'Form 1 West', 'period' => 'Term 1'])
            ->subject(':period results for :learner')
            ->line('The :period results and report card of :learner, :class, are ready.')
            ->line('Ask the school for the report card, or call :school_phone.')
            ->short(':institution: the :period results and report card of :learner, :class, are ready.')
            ->portalLink()
            ->toParty(LearnerContacts::class)
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms, Channel::Email]);
    }

    public function learnerId(): string
    {
        return $this->learner;
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef('result_publication', $this->publicationId);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $this->classId);
    }

    public function tokens(): array
    {
        return [
            'learner' => app(EnrolmentDirectory::class)->names([$this->learner])[$this->learner] ?? null,
            'class' => app(AcademicCalendar::class)->classNames([$this->classId])[$this->classId]['name'] ?? null,
            'period' => $this->period,
        ];
    }

    public function dedupeKey(): string
    {
        return $this->publicationId.':'.$this->learner;
    }

    public function toPayload(): array
    {
        return ['publication' => $this->publicationId, 'learner' => $this->learner, 'class' => $this->classId, 'period' => $this->period];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('publication'), $text('learner'), $text('class'), $text('period'));
    }
}
