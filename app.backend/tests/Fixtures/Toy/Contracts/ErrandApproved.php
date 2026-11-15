<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy\Contracts;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\JourneyTopic;
use Acadly\Kernel\Notify\KernelCategory;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Tests\Fixtures\Toy\TheRequester;
use Tests\Fixtures\Toy\ToyErrand;

final readonly class ErrandApproved implements JourneyTopic
{
    public function __construct(public string $errandId) {}

    public static function notice(): Notice
    {
        return Notice::make('toy.errand_approved')
            ->named('Errand approved')
            ->category(KernelCategory::System)
            ->facts('title', 'errand')
            ->subject(':title was approved')
            ->line('The errand :title was approved by :actor.')
            ->line('Contact :institution with any question.')
            ->short(':title was approved. Open Acadly for details.')
            ->action('Open the errand', '/errands/:errand')
            ->toParty(TheRequester::class)
            ->toDesk()
            ->toPeople()
            ->actedOnWith(ToyPermission::Complete)
            ->channels(default: [Channel::InApp, Channel::Email, Channel::Sms], allowed: [Channel::InApp, Channel::Email, Channel::Sms]);
    }

    public static function fromJourney(JourneyContext $context): static
    {
        return new self($context->subject->auditId());
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
        return [
            'title' => ToyErrand::query()->find($this->errandId)?->title,
            'errand' => $this->errandId,
        ];
    }

    public function dedupeKey(): string
    {
        return 'approved:'.$this->errandId;
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
