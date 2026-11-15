<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Documents\DocumentAccess;
use Acadly\Kernel\Documents\DocumentKind;
use Acadly\Kernel\Documents\Storage\BlobStore;
use Acadly\Kernel\Documents\UploadGuard;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Adds a document of a kind to its owner record, with its first file
 * (version 1). The person needs the kind's write permission at the owner's scope.
 */
final class StoreDocument
{
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly UploadGuard $guard,
        private readonly BlobStore $blobs,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function handle(User $by, DocumentKind $kind, string $ownerId, UploadedFile $upload, ?string $title = null): Document
    {
        if (! $this->access->canStore($by, $kind, $ownerId)) {
            throw new AuthorizationException('Access is refused.');
        }

        $checked = $this->guard->check($upload, $kind, $by);
        $sha = $this->blobs->put((string) $upload->getRealPath(), $checked['mime']);
        $title = self::title($title, $upload);
        $scope = $this->access->scopeOf($kind, $ownerId);

        return DB::transaction(function () use ($by, $kind, $ownerId, $upload, $checked, $sha, $title, $scope): Document {
            $document = Document::query()->create([
                'kind' => $kind->key(),
                'owner_type' => $kind->ownerType(),
                'owner_id' => $ownerId,
                'title' => $title,
                'classification' => $kind->classification()->value,
                'created_by' => $by->id,
            ]);

            $fileId = Ids::next();

            DB::table('document_files')->insert([
                'id' => $fileId,
                'document_id' => $document->id,
                'version_no' => 1,
                'blob_sha256' => $sha,
                'original_name' => self::name($upload),
                'mime_type' => $checked['mime'],
                'byte_size' => $checked['size'],
                'uploaded_by' => $by->id,
                'uploaded_at' => $this->clock->now(),
            ]);

            $document->forceFill(['current_file_id' => $fileId])->save();

            $this->audit->record(KernelAudit::DocumentStored, subject: $document, actor: $this->audit->actorFor($by), at: $scope,
                meta: ['kind' => $kind->key(), 'owner_type' => $kind->ownerType(), 'owner_id' => $ownerId, 'sha256' => $sha]);

            return $document;
        });
    }

    public static function title(?string $title, UploadedFile $upload): string
    {
        $title = trim((string) $title);

        return mb_substr($title !== '' ? $title : pathinfo(self::name($upload), PATHINFO_FILENAME), 0, 255);
    }

    /** The name the file had, without any path, control characters or quotes. */
    public static function name(UploadedFile $upload): string
    {
        $name = basename(str_replace('\\', '/', $upload->getClientOriginalName()));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name);

        return mb_substr($name !== '' ? $name : 'file', 0, 255);
    }
}
