<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy\Contracts;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\KernelCategory;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\OptOut;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;
use Tests\Fixtures\Toy\TheRequester;

/** An urgent notice that cannot be silenced: SMS first, email if the SMS fails. */
final readonly class ErrandEscalated implements Topic
{
    public function __construct(public string $errandId) {}

    public static function notice(): Notice
    {
        return Notice::make('toy.errand_escalated')
            ->named('Errand escalated')
            ->category(KernelCategory::System)
            ->subject('An errand needs attention')
            ->line('An errand needs attention today.')
            ->short('An errand needs attention today. Open Acadly for details.')
            ->toParty(TheRequester::class)
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms, Channel::Email])
            ->optOut(OptOut::Never)
            ->urgent(Channel::Email);
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef('toy_errand', $this->errandId);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::institution();
    }

    public function tokens(): array
    {
        return [];
    }

    public function dedupeKey(): string
    {
        return 'escalated:'.$this->errandId;
    }

    public function toPayload(): array
    {
        return ['errand_id' => $this->errandId];
    }

    public static function fromPayload(array $payload): static
    {
        $id = $payload['errand_id'] ?? '';

        return new self(is_string($id) ? $id : '');
    }
}
