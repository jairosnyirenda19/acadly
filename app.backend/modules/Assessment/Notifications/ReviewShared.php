<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Notifications;

use Acadly\Core\Enrolment\Contracts\AboutLearner;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerContacts;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;

/** A child's progress review was shared with the family (D-094). */
final readonly class ReviewShared implements AboutLearner, Topic
{
    public function __construct(
        public string $reviewId,
        public string $learner,
        public string $classId,
        public string $period,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('assessment.review_shared')->named('Progress review shared')
            ->category(AssessmentCategory::Assessment)
            ->facts('learner', 'period')
            ->examples(['learner' => 'Thokozani Phiri', 'period' => 'Term 3'])
            ->subject('The :period progress review of :learner')
            ->line('The :period progress review of :learner is ready. It shows how :learner is growing in each learning area, and what can help at home.')
            ->line('To talk about it, please call the school on :school_phone.')
            ->short(':institution: the :period progress review of :learner is ready.')
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
        return new SubjectRef('progress_review', $this->reviewId);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $this->classId);
    }

    public function tokens(): array
    {
        return [
            'learner' => app(EnrolmentDirectory::class)->names([$this->learner])[$this->learner] ?? null,
            'period' => $this->period,
        ];
    }

    public function dedupeKey(): string
    {
        return $this->reviewId;
    }

    public function toPayload(): array
    {
        return ['review' => $this->reviewId, 'learner' => $this->learner, 'class' => $this->classId, 'period' => $this->period];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('review'), $text('learner'), $text('class'), $text('period'));
    }
}
