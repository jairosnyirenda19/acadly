<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Documents\Storage\BlobStore;
use Acadly\Kernel\Documents\Storage\StorageMeter;
use Acadly\Kernel\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Empties the trash of documents trashed longer ago than the trash period
 * (run daily by `documents:purge`). A purged document keeps its row, its
 * versions and its audit trail; its bytes are removed when no live document
 * still uses the same content. A held document is never purged.
 */
final class PurgeTrash
{
    public function __construct(
        private readonly BlobStore $blobs,
        private readonly StorageMeter $storage,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @return int the number of documents purged */
    public function handle(): int
    {
        $before = $this->clock->now()->subDays(max(1, config()->integer('acadly.documents.trash_days')));
        $due = Document::query()->whereNotNull('trashed_at')->where('trashed_at', '<', $before)
            ->whereNull('purged_at')->whereNull('held_at')->get();

        foreach ($due as $document) {
            DB::transaction(function () use ($document): void {
                $document->forceFill(['purged_at' => $this->clock->now()])->save();

                $this->audit->record(KernelAudit::DocumentPurged, subject: $document, meta: ['batch' => $document->trash_batch]);
            });

            $files = DB::table('document_files')->where('document_id', $document->id)->pluck('id');
            $shas = DB::table('document_files')->where('document_id', $document->id)->distinct()->pluck('blob_sha256')
                ->merge(DB::table('document_thumbnails')->whereIn('file_id', $files)->pluck('blob_sha256'))->unique();
            // A purged document's pictures go with it; they are not history.
            DB::table('document_thumbnails')->whereIn('file_id', $files)->delete();

            foreach ($shas as $sha) {
                $stillUsed = DB::table('document_files as f')
                    ->join('documents as d', 'd.id', '=', 'f.document_id')
                    ->where('f.blob_sha256', $sha)
                    ->whereNull('d.purged_at')
                    ->exists()
                    || DB::table('document_thumbnails')->where('blob_sha256', $sha)->exists();

                if (! $stillUsed && is_string($sha)) {
                    $this->blobs->release($sha);
                }
            }
        }

        $this->storage->forget();

        return $due->count();
    }
}
