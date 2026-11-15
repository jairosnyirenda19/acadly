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
 * Moves documents to the trash, with a reason (ported from eOffice). Items
 * trashed together share a batch, so a restore brings back exactly that
 * group. A held document cannot be trashed. The trash is emptied after the
 * deployment's trash period by `documents:purge`.
 */
final class TrashDocuments
{
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<Document>  $documents
     * @return string the batch id
     */
    public function handle(User $by, array $documents, string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        foreach ($documents as $document) {
            if (! $document->isLive() || ! $this->access->canWrite($by, $document)) {
                throw (new ModelNotFoundException)->setModel(Document::class);
            }

            if ($document->isHeld()) {
                throw ValidationException::withMessages(['document' => ["\"{$document->title}\" is on hold and cannot be removed."]]);
            }
        }

        $batch = Ids::next();

        DB::transaction(function () use ($by, $documents, $reason, $batch): void {
            foreach ($documents as $document) {
                $changed = Document::query()->whereKey($document->id)->whereNull('trashed_at')->whereNull('held_at')->update([
                    'trashed_at' => $this->clock->now(),
                    'trashed_by' => $by->id,
                    'trash_batch' => $batch,
                    'trash_reason' => $reason,
                ]);

                if ($changed === 0) {
                    throw ValidationException::withMessages(['document' => ['A document changed while the request was in progress.']]);
                }

                $this->audit->record(KernelAudit::DocumentTrashed, subject: $document, reason: $reason,
                    actor: $this->audit->actorFor($by), meta: ['batch' => $batch]);
            }
        });

        return $batch;
    }
}
