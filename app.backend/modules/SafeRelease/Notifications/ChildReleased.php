<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Notifications;

use Acadly\Core\Enrolment\Contracts\AboutLearner;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerContacts;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\OptOut;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;

/** A child was collected (D-094). A safeguarding notice: urgent, and never turned off. */
final readonly class ChildReleased implements AboutLearner, Topic
{
    public function __construct(
        public string $pickupId,
        public string $learner,
        public string $classId,
        public string $time,
        public string $collector,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('saferelease.released')->named('Child collected')
            ->category(SafeReleaseCategory::SafeRelease)
            ->facts('learner', 'time', 'collector')
            ->examples(['learner' => 'Thokozani Phiri', 'time' => '15:42', 'collector' => 'Esther Phiri (Mother)'])
            ->subject(':learner was collected at :time')
            ->line(':learner was collected from school at :time by :collector.')
            ->line('If you did not expect this, call the school now on :school_phone.')
            ->short(':institution: :learner was collected at :time by :collector. Not expected? Call :school_phone now.')
            ->toParty(LearnerContacts::class)
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms, Channel::Email])
            ->optOut(OptOut::Never)
            ->urgent(Channel::Sms);
    }

    public function learnerId(): string
    {
        return $this->learner;
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef('pickup', $this->pickupId);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $this->classId);
    }

    public function tokens(): array
    {
        return [
            'learner' => app(EnrolmentDirectory::class)->names([$this->learner])[$this->learner] ?? null,
            'time' => $this->time,
            'collector' => $this->collector,
        ];
    }

    public function dedupeKey(): string
    {
        return $this->pickupId;
    }

    public function toPayload(): array
    {
        return ['pickup' => $this->pickupId, 'learner' => $this->learner, 'class' => $this->classId, 'time' => $this->time, 'collector' => $this->collector];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('pickup'), $text('learner'), $text('class'), $text('time'), $text('collector'));
    }
}
