<?php

declare(strict_types=1);

namespace Acadly\Kernel\Grid;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\KernelCategory;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;

/** An export a person asked for is ready to download. Only that person is told. */
final readonly class ExportReady implements Topic
{
    public const string SUBJECT = 'grids.export';

    public function __construct(
        public string $exportId,
        public string $list,
        public int $rows,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('notify.export_ready')
            ->named('Export ready')
            ->category(KernelCategory::System)
            ->facts('list', 'rows', 'export')
            ->examples(['list' => 'People', 'rows' => '186', 'export' => '0190f8a2-0000-7000-8000-000000000000'])
            ->subject(':list export is ready')
            ->line('The :list export is ready: :rows rows. It is kept for 7 days, then deleted.')
            ->action('Download', '/exports/:export')
            ->toPeople()
            ->channels(default: [Channel::InApp], allowed: [Channel::InApp]);
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef(self::SUBJECT, $this->exportId);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::institution();
    }

    public function tokens(): array
    {
        return ['list' => $this->list, 'rows' => (string) $this->rows, 'export' => $this->exportId];
    }

    public function dedupeKey(): string
    {
        return 'export:'.$this->exportId;
    }

    public function toPayload(): array
    {
        return ['export' => $this->exportId, 'list' => $this->list, 'rows' => $this->rows];
    }

    public static function fromPayload(array $payload): static
    {
        $rows = $payload['rows'] ?? 0;

        return new self(is_string($payload['export'] ?? null) ? $payload['export'] : '', is_string($payload['list'] ?? null) ? $payload['list'] : '', is_int($rows) ? $rows : 0);
    }
}
