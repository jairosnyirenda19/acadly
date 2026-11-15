<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Documents\DocumentAccess;
use Acadly\Kernel\Documents\DocumentCatalogue;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brings back an earlier version by adding it again as the newest one.
 * History is never rewritten: the versions in between stay.
 */
final class RestoreVersion
{
    public function __construct(
        private readonly DocumentCatalogue $kinds,
        private readonly DocumentAccess $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function handle(User $by, Document $document, int $versionNo): Document
    {
        if (! $document->isLive() || ! $this->access->canWrite($by, $document)) {
            throw (new ModelNotFoundException)->setModel(Document::class);
        }

        return DB::transaction(function () use ($by, $document, $versionNo): Document {
            $locked = Document::query()->lockForUpdate()->findOrFail($document->id);
            $old = DB::table('document_files')->where('document_id', $locked->id)->where('version_no', $versionNo)->first();

            if ($old === null) {
                throw ValidationException::withMessages(['version' => ['The version does not exist.']]);
            }

            if ($old->id === $locked->current_file_id) {
                throw ValidationException::withMessages(['version' => ['This version is already the current one.']]);
            }

            $version = AddVersion::nextVersion($locked->id);
            $fileId = Ids::next();

            DB::table('document_files')->insert([
                'id' => $fileId,
                'document_id' => $locked->id,
                'version_no' => $version,
                'blob_sha256' => $old->blob_sha256,
                'original_name' => $old->original_name,
                'mime_type' => $old->mime_type,
                'byte_size' => $old->byte_size,
                'note' => "Restored from version {$versionNo}.",
                'uploaded_by' => $by->id,
                'uploaded_at' => $this->clock->now(),
            ]);

            $locked->forceFill(['current_file_id' => $fileId])->save();

            $this->audit->record(KernelAudit::VersionRestored, subject: $locked, actor: $this->audit->actorFor($by),
                at: $this->access->scopeOf($this->kinds->get($locked->kind), $locked->owner_id),
                meta: ['from_version' => $versionNo, 'version' => $version]);

            return $locked;
        });
    }
}
