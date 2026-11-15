<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Documents\DocumentAccess;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Notes that a document also concerns another record: a marked script owned
 * by a learner is linked to the assessment and the class. Only a person who
 * may open the document may link it, and the link gives nobody access.
 * The module that calls this has already checked the person may touch the record.
 */
final class LinkDocument
{
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function handle(User $by, Document $document, string $recordType, string $recordId): void
    {
        if (! $document->isLive() || ! $this->access->canRead($by, $document)) {
            throw (new ModelNotFoundException)->setModel(Document::class);
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $recordType) !== 1 || $recordId === '' || strlen($recordId) > 64) {
            throw ValidationException::withMessages(['record' => ['The record is not valid.']]);
        }

        if ($recordType === $document->owner_type && $recordId === $document->owner_id) {
            throw ValidationException::withMessages(['record' => ['The record already owns the document.']]);
        }

        DB::transaction(function () use ($by, $document, $recordType, $recordId): void {
            $inserted = DB::table('document_links')->insertOrIgnore([
                'id' => Ids::next(),
                'document_id' => $document->id,
                'record_type' => $recordType,
                'record_id' => $recordId,
                'linked_by' => $by->id,
                'linked_at' => $this->clock->now(),
            ]);

            if ($inserted === 0) {
                throw ValidationException::withMessages(['record' => ['The document is already linked to the record.']]);
            }

            $this->audit->record(KernelAudit::DocumentLinked, subject: $document, actor: $this->audit->actorFor($by),
                meta: ['record_type' => $recordType, 'record_id' => $recordId]);
        });
    }
}
