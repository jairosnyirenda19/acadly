<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents;

use Acadly\Kernel\Support\Row;

/** One version of a document, as read from `document_files`. */
final readonly class DocumentFile
{
    public function __construct(
        public string $id,
        public int $version,
        public string $sha,
        public string $name,
        public string $mime,
        public int $size,
        public ?string $note,
        public string $uploadedAt,
    ) {}

    public static function fromRow(object $row): self
    {
        return new self(Row::text($row, 'id'), Row::int($row, 'version_no'), Row::text($row, 'blob_sha256'),
            Row::text($row, 'original_name'), Row::text($row, 'mime_type'), Row::int($row, 'byte_size'),
            Row::maybeText($row, 'note'), Row::text($row, 'uploaded_at'));
    }

    /** @return array{version: int, name: string, type: string, size: int, note: string|null, uploaded_at: string} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'name' => $this->name, 'type' => $this->mime, 'size' => $this->size,
            'note' => $this->note, 'uploaded_at' => $this->uploadedAt];
    }
}
