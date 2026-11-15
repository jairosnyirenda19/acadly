<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Application\Actions;

use Acadly\Core\Institution\Contracts\InstitutionAudit;
use Acadly\Core\Institution\Contracts\InstitutionDocument;
use Acadly\Core\Institution\Domain\School;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Documents\Actions\AddVersion;
use Acadly\Kernel\Documents\Actions\StoreDocument;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Identity\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Sets the school logo. The logo is a document the school owns: the first
 * upload makes it, each later one is a new version, so earlier logos stay
 * in its history. The document gate checks the file and the permission.
 */
final class ReplaceLogo
{
    public function __construct(
        private readonly StoreDocument $store,
        private readonly AddVersion $addVersion,
        private readonly Audit $audit,
    ) {}

    public function handle(User $by, UploadedFile $upload): School
    {
        $school = School::current();
        $existing = $school->logo_document_id !== null ? Document::query()->live()->find($school->logo_document_id) : null;

        $document = $existing instanceof Document
            ? $this->addVersion->handle($by, $existing, $upload)
            : $this->store->handle($by, InstitutionDocument::Logo, $school->id, $upload, 'School logo');

        return DB::transaction(function () use ($by, $school, $document): School {
            $school->forceFill(['logo_document_id' => $document->id])->save();

            $this->audit->record(InstitutionAudit::LogoReplaced, subject: $school, actor: $this->audit->actorFor($by),
                meta: ['document_id' => $document->id]);

            return $school;
        });
    }
}
