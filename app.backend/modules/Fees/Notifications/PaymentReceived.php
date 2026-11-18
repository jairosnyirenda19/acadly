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

/** A receipt, by text message: one child's share of a payment, and what is left to pay. */
final readonly class PaymentReceived implements AboutLearner, Topic
{
    public const string SUBJECT = 'fees.payment_share';

    public function __construct(
        public string $shareId,
        public string $learner,
        public string $classId,
        public string $receipt,
        public int $amount,
        public int $balance,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('fees.payment_received')->named('Fee payment received')
            ->category(FeesCategory::Fees)
            ->facts('learner', 'amount', 'receipt', 'balance')
            ->examples(['learner' => 'Alinafe Banda', 'amount' => 'MK 90,000', 'receipt' => 'RCT-2028-000530', 'balance' => 'MK 0'])
            ->subject('Fee payment received for :learner')
            ->line('We received :amount for :learner. Receipt :receipt.')
            ->line('Left to pay this school year: :balance.')
            ->short(':institution: received :amount for :learner, receipt :receipt. Left to pay: :balance.')
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms, Channel::Email])
            ->toParty(LearnerContacts::class);
    }

    public function learnerId(): string
    {
        return $this->learner;
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef(self::SUBJECT, $this->shareId);
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
            'receipt' => $this->receipt,
            'balance' => Money::text(max(0, $this->balance)),
        ];
    }

    public function dedupeKey(): string
    {
        return $this->shareId;
    }

    public function toPayload(): array
    {
        return ['share' => $this->shareId, 'learner' => $this->learner, 'class' => $this->classId, 'receipt' => $this->receipt, 'amount' => $this->amount, 'balance' => $this->balance];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';
        $int = fn (string $key): int => is_int($payload[$key] ?? null) ? $payload[$key] : 0;

        return new self($text('share'), $text('learner'), $text('class'), $text('receipt'), $int('amount'), $int('balance'));
    }
}
