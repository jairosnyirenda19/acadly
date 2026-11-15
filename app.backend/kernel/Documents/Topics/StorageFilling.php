<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Topics;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Documents\DocumentPermission;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\KernelCategory;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;

/** Document storage has crossed 80% or 95% of the licence's limit. */
final readonly class StorageFilling implements Topic
{
    public function __construct(
        public int $level,
        public int $percent,
        public string $day,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('documents.storage_filling')
            ->named('Document storage filling up')
            ->category(KernelCategory::System)
            ->facts('percent')
            ->subject('Document storage is :percent% full')
            ->line('Document storage is :percent% full.')
            ->line('When it is full, new documents that are not essential records are refused. Contact IQ-X about a larger allowance.')
            ->toDesk()
            ->actedOnWith(DocumentPermission::Manage)
            ->channels(default: [Channel::InApp, Channel::Email], allowed: [Channel::InApp, Channel::Email]);
    }

    public function subject(): SubjectRef
    {
        return SubjectRef::none();
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::institution();
    }

    public function tokens(): array
    {
        return ['percent' => (string) $this->percent];
    }

    public function dedupeKey(): string
    {
        return "storage:{$this->level}:{$this->day}";
    }

    public function toPayload(): array
    {
        return ['level' => $this->level, 'percent' => $this->percent, 'day' => $this->day];
    }

    public static function fromPayload(array $payload): static
    {
        $level = $payload['level'] ?? 0;
        $percent = $payload['percent'] ?? 0;
        $day = $payload['day'] ?? '';

        return new self(is_int($level) ? $level : 0, is_int($percent) ? $percent : 0, is_string($day) ? $day : '');
    }
}
