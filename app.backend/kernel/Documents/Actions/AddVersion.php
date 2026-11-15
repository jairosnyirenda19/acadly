<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Documents\DocumentAccess;
use Acadly\Kernel\Documents\DocumentCatalogue;
use Acadly\Kernel\Documents\Storage\BlobStore;
use Acadly\Kernel\Documents\UploadGuard;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a new version of a document. Earlier versions are kept as they were;
 * the document points at the new one. A held document can still take a new
 * version: a hold stops removal, not work.
 */
final class AddVersion
{
    public function __construct(
        private readonly DocumentCatalogue $kinds,
        private readonly DocumentAccess $access,
        private readonly UploadGuard $guard,
        private readonly BlobStore $blobs,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function handle(User $by, Document $document, UploadedFile $upload, ?string $note = null): Document
    {
        if (! $document->isLive() || ! $this->access->canWrite($by, $document)) {
            throw (new ModelNotFoundException)->setModel(Document::class);
        }

        $kind = $this->kinds->get($document->kind);
        $checked = $this->guard->check($upload, $kind, $by);
        $sha = $this->blobs->put((string) $upload->getRealPath(), $checked['mime']);

        return DB::transaction(function () use ($by, $document, $upload, $note, $checked, $sha, $kind): Document {
            $locked = Document::query()->lockForUpdate()->findOrFail($document->id);

            if (! $locked->isLive()) {
                throw ValidationException::withMessages(['document' => ['The document is in the trash.']]);
            }

            $version = self::nextVersion($locked->id);
            $fileId = Ids::next();
            $note = trim((string) $note);

            DB::table('document_files')->insert([
                'id' => $fileId,
                'document_id' => $locked->id,
                'version_no' => $version,
                'blob_sha256' => $sha,
                'original_name' => StoreDocument::name($upload),
                'mime_type' => $checked['mime'],
                'byte_size' => $checked['size'],
                'note' => $note !== '' ? mb_substr($note, 0, 1000) : null,
                'uploaded_by' => $by->id,
                'uploaded_at' => $this->clock->now(),
            ]);

            $locked->forceFill(['current_file_id' => $fileId])->save();

            $this->audit->record(KernelAudit::VersionAdded, subject: $locked, actor: $this->audit->actorFor($by),
                at: $this->access->scopeOf($kind, $locked->owner_id), meta: ['version' => $version, 'sha256' => $sha]);

            return $locked;
        });
    }

    public static function nextVersion(string $documentId): int
    {
        $max = DB::table('document_files')->where('document_id', $documentId)->max('version_no');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }
}
