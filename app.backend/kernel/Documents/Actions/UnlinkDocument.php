<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Documents\DocumentAccess;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Ends a link. The link row stays, with who ended it and when. */
final class UnlinkDocument
{
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function handle(User $by, Document $document, string $recordType, string $recordId): void
    {
        if (! $this->access->canWrite($by, $document)) {
            throw (new ModelNotFoundException)->setModel(Document::class);
        }

        DB::transaction(function () use ($by, $document, $recordType, $recordId): void {
            $ended = DB::table('document_links')
                ->where('document_id', $document->id)->where('record_type', $recordType)->where('record_id', $recordId)
                ->whereNull('unlinked_at')
                ->update(['unlinked_at' => $this->clock->now(), 'unlinked_by' => $by->id]);

            if ($ended === 0) {
                throw ValidationException::withMessages(['record' => ['There is no such link.']]);
            }

            $this->audit->record(KernelAudit::DocumentUnlinked, subject: $document, actor: $this->audit->actorFor($by),
                meta: ['record_type' => $recordType, 'record_id' => $recordId]);
        });
    }
}
