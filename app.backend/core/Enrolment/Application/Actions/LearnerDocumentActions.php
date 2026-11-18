<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Actions;

use Acadly\Core\Enrolment\Application\Reach;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Domain\LearnerDocumentAccess;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Documents\Actions\StoreDocument;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Documents\ImagesToPdf;
use Acadly\Kernel\Documents\Thumbnails;
use Acadly\Kernel\Documents\UploadGuard;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds documents to a learner's record and keeps the school's list of
 * learner document types (D-090).
 */
final class LearnerDocumentActions
{
    /** The most pages one scan may have. */
    public const int MAX_PAGES = 20;

    public function __construct(
        private readonly Reach $reach,
        private readonly StoreDocument $store,
        private readonly UploadGuard $guard,
        private readonly Thumbnails $thumbnails,
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * One file, or several photos of a paper document that become one PDF.
     *
     * @param  list<UploadedFile>  $files
     * @param  UploadedFile|null  $preview  a picture of a PDF's first page, drawn by the browser
     */
    public function add(User $by, string $learnerId, string $typeId, ?string $title, ?string $date, array $files, ?UploadedFile $preview = null): Document
    {
        ['learner' => $learner, 'at' => $at] = $this->reach->learner($by, $learnerId, EnrolmentPermission::View);
        $type = DB::table('learner_document_types')->where('id', $typeId)->whereNull('retired_at')->first(['id', 'name', 'access']);

        if ($type === null) {
            throw ValidationException::withMessages(['type_id' => ['Choose a document type.']]);
        }

        $kind = LearnerDocumentAccess::from(Row::text($type, 'access'))->kind();
        $this->access->check($by, $kind->writePermission(), $at);

        if ($files === [] || count($files) > self::MAX_PAGES) {
            throw ValidationException::withMessages(['files' => ['Add from 1 to '.self::MAX_PAGES.' pages.']]);
        }

        $title = trim((string) $title);
        $title = mb_substr($title !== '' ? $title : Row::text($type, 'name'), 0, 255);
        $scan = null;
        $firstPhoto = null;

        if (count($files) > 1) {
            foreach ($files as $file) {
                if (! in_array($this->guard->check($file, $kind, $by)['mime'], Thumbnails::IMAGES, true)) {
                    throw ValidationException::withMessages(['files' => ['Several pages must all be photos.']]);
                }
            }

            $scan = ImagesToPdf::make(array_map(fn (UploadedFile $f): string => (string) $f->getRealPath(), $files));
            $upload = new UploadedFile($scan, $title.'.pdf', 'application/pdf', null, true);
            $firstPhoto = (string) $files[0]->getRealPath();
        } else {
            $upload = $files[0];
        }

        try {
            $document = DB::transaction(function () use ($by, $kind, $learner, $at, $upload, $title, $type, $date): Document {
                $document = $this->store->handle($by, $kind, $learner->id, $upload, $title);
                DB::table('learner_documents')->insert([
                    'document_id' => $document->id,
                    'learner_id' => $learner->id,
                    'type_id' => Row::text($type, 'id'),
                    'document_date' => $date,
                    'created_at' => $this->clock->now(),
                ]);
                $this->audit->record(EnrolmentAudit::DocumentAdded, subject: $learner, actor: $this->audit->actorFor($by), at: $at,
                    meta: ['document_id' => $document->id, 'type' => Row::text($type, 'name')]);

                return $document;
            });
        } finally {
            if ($scan !== null) {
                @unlink($scan);
            }
        }

        // The picture, once the document is kept. An image is drawn on the queue by the kernel.
        if ($document->current_file_id !== null) {
            if ($firstPhoto !== null) {
                $this->thumbnails->fromPath($document->current_file_id, $firstPhoto);
            } elseif ($preview !== null) {
                $this->thumbnails->fromPreview($document->current_file_id, $preview);
            }
        }

        return $document;
    }

    /** Adds a type, or changes one. A change of who may open it applies to its documents at once. */
    public function saveType(User $by, ?string $id, string $name, LearnerDocumentAccess $access, bool $expected): string
    {
        $this->access->check($by, EnrolmentPermission::Manage);
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => ['Give the type a name of up to 80 characters.']]);
        }

        $taken = DB::table('learner_document_types')->whereNull('retired_at')->whereRaw('lower(name) = lower(?)', [$name])
            ->when($id !== null, fn ($q) => $q->where('id', '!=', $id))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => ['The school already has a type with this name.']]);
        }

        return DB::transaction(function () use ($by, $id, $name, $access, $expected): string {
            $now = $this->clock->now();

            if ($id === null) {
                $id = Ids::next();
                $position = DB::table('learner_document_types')->max('position');
                DB::table('learner_document_types')->insert(['id' => $id, 'name' => $name, 'access' => $access->value, 'expected' => $expected,
                    'position' => (is_numeric($position) ? (int) $position : 0) + 1, 'created_at' => $now, 'updated_at' => $now]);
                $this->audit->record(EnrolmentAudit::DocumentTypeSaved, actor: $this->audit->actorFor($by),
                    meta: ['type_id' => $id, 'name' => $name, 'access' => $access->value, 'expected' => $expected]);

                return $id;
            }

            $type = DB::table('learner_document_types')->where('id', $id)->whereNull('retired_at')->lockForUpdate()->first(['access', 'name']);

            if ($type === null) {
                throw ValidationException::withMessages(['type' => ['The type is retired or no longer exists.']]);
            }

            $was = LearnerDocumentAccess::from(Row::text($type, 'access'));
            $moved = 0;

            if ($was !== $access) {
                $kind = $access->kind();
                $moved = DB::table('documents')->whereIn('id', DB::table('learner_documents')->where('type_id', $id)->select('document_id'))
                    ->update(['kind' => $kind->key(), 'classification' => $kind->classification()->value, 'updated_at' => $now]);
            }

            DB::table('learner_document_types')->where('id', $id)->update(['name' => $name, 'access' => $access->value, 'expected' => $expected, 'updated_at' => $now]);
            $this->audit->record(EnrolmentAudit::DocumentTypeSaved, actor: $this->audit->actorFor($by),
                meta: ['type_id' => $id, 'name' => $name, 'access' => $access->value, 'access_was' => $was->value, 'expected' => $expected, 'documents_moved' => $moved]);

            return $id;
        });
    }

    /** A retired type takes no new documents. Its documents stay as they are. */
    public function retireType(User $by, string $id): void
    {
        $this->access->check($by, EnrolmentPermission::Manage);

        DB::transaction(function () use ($by, $id): void {
            $changed = DB::table('learner_document_types')->where('id', $id)->whereNull('retired_at')
                ->update(['retired_at' => $this->clock->now(), 'updated_at' => $this->clock->now()]);

            if ($changed === 0) {
                throw ValidationException::withMessages(['type' => ['The type is retired or no longer exists.']]);
            }

            $this->audit->record(EnrolmentAudit::DocumentTypeRetired, actor: $this->audit->actorFor($by), meta: ['type_id' => $id]);
        });
    }
}
