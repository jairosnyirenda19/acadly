<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Documents\DocumentAccess;
use Acadly\Kernel\Identity\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/** Brings back every document of one trash batch, if the trash has not been emptied. */
final class RestoreTrash
{
    public function __construct(
        private readonly DocumentAccess $access,
        private readonly Audit $audit,
    ) {}

    /** @return int the number of documents restored */
    public function handle(User $by, string $batch): int
    {
        $documents = Document::query()->where('trash_batch', $batch)->whereNotNull('trashed_at')->whereNull('purged_at')->get();

        if ($documents->isEmpty() || $documents->contains(fn (Document $document): bool => ! $this->access->canWrite($by, $document))) {
            throw (new ModelNotFoundException)->setModel(Document::class);
        }

        DB::transaction(function () use ($by, $documents, $batch): void {
            foreach ($documents as $document) {
                $document->forceFill(['trashed_at' => null, 'trashed_by' => null, 'trash_batch' => null, 'trash_reason' => null])->save();

                $this->audit->record(KernelAudit::DocumentRestored, subject: $document, actor: $this->audit->actorFor($by), meta: ['batch' => $batch]);
            }
        });

        return $documents->count();
    }
}
