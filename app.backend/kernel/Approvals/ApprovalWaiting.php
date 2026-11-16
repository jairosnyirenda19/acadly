<?php

declare(strict_types=1);

namespace Acadly\Kernel\Approvals;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\KernelCategory;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/** A step of an approval is waiting: the people it asks are told. */
final readonly class ApprovalWaiting implements Topic
{
    public function __construct(
        public string $requestId,
        public int $step,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('notify.approval_waiting')
            ->named('Approval waiting')
            ->category(KernelCategory::System)
            ->facts('kind', 'what', 'where', 'request')
            ->examples(['kind' => 'Register correction', 'what' => 'Chikondi Banda: absent to present', 'where' => 'Form 1 East · 16 Nov', 'request' => '0190f8a2-0000-7000-8000-000000000000'])
            ->subject('To approve: :kind')
            ->line(':kind waiting for your decision: :what, :where.')
            ->action('Open it', '/approvals/:request')
            ->toParty(TheAsked::class)
            ->channels(default: [Channel::InApp], allowed: [Channel::InApp, Channel::Email]);
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef('approval_request', $this->requestId);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::institution();
    }

    public function tokens(): array
    {
        $request = DB::table('approval_requests')->where('id', $this->requestId)->first(['topic_key', 'subject_id']);
        $topic = $request !== null ? app(Approvals::class)->topic(Row::text($request, 'topic_key')) : null;
        $case = $request !== null ? $topic?->case(Row::text($request, 'subject_id')) : null;

        return ['kind' => $topic?->title(), 'what' => $case?->title, 'where' => $case?->where, 'request' => $this->requestId];
    }

    public function dedupeKey(): string
    {
        return 'approval:'.$this->requestId.':'.$this->step;
    }

    public function toPayload(): array
    {
        return ['request' => $this->requestId, 'step' => $this->step];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(is_string($payload['request'] ?? null) ? $payload['request'] : '', is_int($payload['step'] ?? null) ? $payload['step'] : 0);
    }
}
