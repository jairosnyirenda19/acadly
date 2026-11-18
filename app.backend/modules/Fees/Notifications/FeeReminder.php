<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Notifications;

use Acadly\Core\Enrolment\Contracts\AboutLearner;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerContacts;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;
use Acadly\Modules\Fees\Domain\Money;
use Carbon\CarbonImmutable;

/**
 * A reminder that fees are due: the amount and the date only. It never names
 * another child or the class. At most one a day for a learner.
 */
final readonly class FeeReminder implements AboutLearner, Topic
{
    public const string SUBJECT = 'fees.reminder';

    public function __construct(
        public string $learner,
        public string $classId,
        public int $amount,
        public string $dueOn,
        public string $day,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('fees.reminder')->named('Fees due')
            ->category(FeesCategory::Fees)
            ->facts('learner', 'amount', 'due')
            ->examples(['learner' => 'Alinafe Banda', 'amount' => 'MK 19,000', 'due' => '6 Feb'])
            ->subject('Fees due for :learner')
            ->line(':amount of the fees for :learner was due on :due.')
            ->line('Please pay at the school office, or call :school_phone to agree a plan.')
            ->short(':institution: :amount of the fees for :learner was due on :due. Please pay, or call :school_phone.')
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms, Channel::Email])
            ->toParty(LearnerContacts::class);
    }

    public function learnerId(): string
    {
        return $this->learner;
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef(self::SUBJECT, $this->learner.':'.$this->day);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $this->classId);
    }

    public function tokens(): array
    {
        return [
            'learner' => app(EnrolmentDirectory::class)->names([$this->learner])[$this->learner] ?? null,
            'amount' => Money::text($this->amount),
            'due' => CarbonImmutable::parse($this->dueOn)->format('j M'),
        ];
    }

    public function dedupeKey(): string
    {
        return $this->learner.':'.$this->day;
    }

    public function toPayload(): array
    {
        return ['learner' => $this->learner, 'class' => $this->classId, 'amount' => $this->amount, 'due' => $this->dueOn, 'day' => $this->day];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('learner'), $text('class'), is_int($payload['amount'] ?? null) ? $payload['amount'] : 0, $text('due'), $text('day'));
    }
}
