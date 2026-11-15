<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Documents\Storage\BlobStore;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Reads documents for a person: the ones they may open, never more. A
 * document they may not open is "not found". Opening the bytes of a
 * personal or secret document is an audit line.
 */
final class DocumentReader
{
    private const array FILE_COLUMNS = ['id', 'version_no', 'blob_sha256', 'original_name', 'mime_type', 'byte_size', 'note', 'uploaded_at'];

    public function __construct(
        private readonly DocumentAccess $access,
        private readonly DocumentCatalogue $kinds,
        private readonly BlobStore $blobs,
        private readonly Audit $audit,
    ) {}

    /** A live document the person may open. */
    public function find(User $who, string $id): Document
    {
        $document = Document::query()->live()->find($id);

        if (! $document instanceof Document || ! $this->access->canRead($who, $document)) {
            throw (new ModelNotFoundException)->setModel(Document::class);
        }

        return $document;
    }

    /**
     * The live documents a record owns that the person may open, newest first.
     *
     * @return Collection<int, Document>
     */
    public function ownedBy(User $who, string $ownerType, string $ownerId, ?string $kind = null): Collection
    {
        return Document::query()->live()
            ->where('owner_type', $ownerType)->where('owner_id', $ownerId)
            ->when($kind !== null, fn (Builder $query): Builder => $query->where('kind', $kind))
            ->orderByDesc('created_at')->get()
            ->filter(fn (Document $document): bool => $this->access->canRead($who, $document))
            ->values();
    }

    /**
     * The live documents linked to a record that the person may open.
     *
     * @return Collection<int, Document>
     */
    public function linkedTo(User $who, string $recordType, string $recordId): Collection
    {
        $ids = DB::table('document_links')->where('record_type', $recordType)->where('record_id', $recordId)
            ->whereNull('unlinked_at')->pluck('document_id')->all();

        return Document::query()->live()->whereIn('id', $ids)->orderByDesc('created_at')->get()
            ->filter(fn (Document $document): bool => $this->access->canRead($who, $document))
            ->values();
    }

    /**
     * The current version of a live document of a public kind, for anyone.
     *
     * @return array{file: DocumentFile, bytes: Generator<int, string>}
     */
    public function openPublic(string $id): array
    {
        $document = Document::query()->live()->find($id);

        if (! $document instanceof Document || ! $this->kinds->has($document->kind) || ! $this->kinds->get($document->kind)->isPublic()) {
            throw (new ModelNotFoundException)->setModel(Document::class);
        }

        $row = DB::table('document_files')->where('id', $document->current_file_id)->first(self::FILE_COLUMNS);

        if ($row === null) {
            throw (new ModelNotFoundException)->setModel(Document::class);
        }

        $file = DocumentFile::fromRow($row);

        return ['file' => $file, 'bytes' => $this->blobs->read($file->sha)];
    }

    /** @return list<DocumentFile> every version, newest first */
    public function versions(Document $document): array
    {
        return array_values(DB::table('document_files')->where('document_id', $document->id)->orderByDesc('version_no')
            ->get(self::FILE_COLUMNS)->map(fn (object $row): DocumentFile => DocumentFile::fromRow($row))->all());
    }

    /** @return list<array{record_type: string, record_id: string}> the records the document is linked to */
    public function links(Document $document): array
    {
        return array_values(DB::table('document_links')->where('document_id', $document->id)->whereNull('unlinked_at')
            ->orderBy('linked_at')->get(['record_type', 'record_id'])
            ->map(fn (object $row): array => ['record_type' => Row::text($row, 'record_type'), 'record_id' => Row::text($row, 'record_id')])
            ->all());
    }

    /**
     * Opens one version (the current one by default) and records the read
     * when the kind is personal or secret.
     *
     * @return array{file: DocumentFile, bytes: Generator<int, string>}
     */
    public function open(User $who, Document $document, ?int $version = null): array
    {
        $query = DB::table('document_files')->where('document_id', $document->id);
        $row = $version === null
            ? $query->where('id', $document->current_file_id)->first(self::FILE_COLUMNS)
            : $query->where('version_no', $version)->first(self::FILE_COLUMNS);

        if ($row === null) {
            throw (new ModelNotFoundException)->setModel(Document::class);
        }

        $file = DocumentFile::fromRow($row);
        $kind = $this->kinds->get($document->kind);

        if ($kind->classification() !== FieldClass::Plain) {
            $this->audit->record(KernelAudit::DocumentRead, subject: $document, actor: $this->audit->actorFor($who),
                at: $this->access->scopeOf($kind, $document->owner_id), meta: ['version' => $file->version]);
        }

        return ['file' => $file, 'bytes' => $this->blobs->read($file->sha)];
    }
}
