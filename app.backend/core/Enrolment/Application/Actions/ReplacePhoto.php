<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Actions;

use Acadly\Core\Enrolment\Application\Reach;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentDocument;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Documents\Actions\AddVersion;
use Acadly\Kernel\Documents\Actions\StoreDocument;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Identity\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Sets a learner's photo (optional). The photo is a document the learner
 * record owns: the first upload makes it, each later one is a new version.
 * The document gate checks the file, its type and size, and the permission.
 */
final class ReplacePhoto
{
    public function __construct(
        private readonly Reach $reach,
        private readonly StoreDocument $store,
        private readonly AddVersion $addVersion,
        private readonly Audit $audit,
    ) {}

    public function handle(User $by, string $learnerId, UploadedFile $upload): void
    {
        ['learner' => $learner, 'at' => $at] = $this->reach->learner($by, $learnerId, EnrolmentPermission::Manage);
        $existing = $learner->photo_document_id !== null ? Document::query()->live()->find($learner->photo_document_id) : null;

        $document = $existing instanceof Document
            ? $this->addVersion->handle($by, $existing, $upload)
            : $this->store->handle($by, EnrolmentDocument::Photo, $learner->id, $upload, 'Photo');

        DB::transaction(function () use ($by, $learner, $at, $document): void {
            $learner->forceFill(['photo_document_id' => $document->id])->save();
            $this->audit->record(EnrolmentAudit::PhotoReplaced, subject: $learner, actor: $this->audit->actorFor($by), at: $at,
                meta: ['document_id' => $document->id]);
        });
    }
}
