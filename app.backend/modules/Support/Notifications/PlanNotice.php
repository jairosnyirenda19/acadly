<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Notifications;

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
 * The family is told a support plan has opened or closed (D-093). Never for
 * a confidential plan. It names the plan's title and the person who leads it.
 */
final readonly class PlanNotice implements AboutLearner, Topic
{
    public const string SUBJECT = 'support.plan';

    public function __construct(
        public string $planId,
        public string $learner,
        public string $classId,
        public string $stage,
        public string $title,
        public string $lead,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('support.plan')->named('Support plan')
            ->category(SupportCategory::Support)
            ->facts('learner', 'what', 'title', 'lead')
            ->examples(['learner' => 'Tiwonge Mwale', 'what' => 'opened', 'title' => 'Attendance and Mathematics', 'lead' => 'Ruth Banda'])
            ->subject('A support plan for :learner has :what')
            ->line('The school has :what a support plan for :learner: :title. It is led by :lead.')
            ->line('To talk about it, please call the school on :school_phone.')
            ->short(':institution: a support plan for :learner has :what (:title), led by :lead. Call :school_phone to talk about it.')
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms, Channel::Email])
            ->toParty(LearnerContacts::class);
    }

    public function learnerId(): string
    {
        return $this->learner;
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef(self::SUBJECT, $this->planId.':'.$this->stage);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $this->classId);
    }

    public function tokens(): array
    {
        return [
            'learner' => app(EnrolmentDirectory::class)->names([$this->learner])[$this->learner] ?? null,
            'what' => $this->stage,
            'title' => $this->title,
            'lead' => $this->lead,
        ];
    }

    public function dedupeKey(): string
    {
        return $this->planId.':'.$this->stage;
    }

    public function toPayload(): array
    {
        return ['plan' => $this->planId, 'learner' => $this->learner, 'class' => $this->classId, 'stage' => $this->stage, 'title' => $this->title, 'lead' => $this->lead];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('plan'), $text('learner'), $text('class'), $text('stage'), $text('title'), $text('lead'));
    }
}
