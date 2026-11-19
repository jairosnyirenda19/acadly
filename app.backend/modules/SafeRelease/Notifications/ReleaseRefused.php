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
use Acadly\Modules\SafeRelease\Contracts\SafeReleasePermission;

/**
 * Someone not on the list came to collect a child, and the child was kept
 * (D-094). The family and the staff who keep pick-up are told. Urgent, and
 * never turned off.
 */
final readonly class ReleaseRefused implements AboutLearner, Topic
{
    public function __construct(
        public string $pickupId,
        public string $learner,
        public string $classId,
        public string $time,
        public string $person,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('saferelease.refused')->named('Child not released')
            ->category(SafeReleaseCategory::SafeRelease)
            ->facts('learner', 'time', 'person')
            ->examples(['learner' => 'Thokozani Phiri', 'time' => '15:50', 'person' => 'Peter Gondwe'])
            ->subject(':learner was not released at :time')
            ->line(':person came to collect :learner at :time and is not on the list of who may collect. :learner is safe at school.')
            ->line('Call the school now on :school_phone.')
            ->short(':institution: :person came to collect :learner at :time and is not on your list. :learner is safe at school. Call :school_phone now.')
            ->toParty(LearnerContacts::class)
            ->toDesk()
            ->actedOnWith(SafeReleasePermission::Manage)
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
            'person' => $this->person,
        ];
    }

    public function dedupeKey(): string
    {
        return $this->pickupId;
    }

    public function toPayload(): array
    {
        return ['pickup' => $this->pickupId, 'learner' => $this->learner, 'class' => $this->classId, 'time' => $this->time, 'person' => $this->person];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('pickup'), $text('learner'), $text('class'), $text('time'), $text('person'));
    }
}
