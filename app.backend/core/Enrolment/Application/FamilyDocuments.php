<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Enrolment\Contracts\Guardianship;
use Acadly\Core\Enrolment\Domain\LearnerDocumentAccess;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Documents\DocumentReader;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Phone;
use Acadly\Kernel\Support\Row;
use Generator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A learner's documents in the parent portal (D-110). A confirmed parent sees
 * the documents of the types the school shows to families: only types every
 * staff member who sees the learner may open. Office and confidential types
 * never. Unticking a type hides its documents at once. Each opening is a line
 * on the document's history. Anything else is "not found".
 */
final class FamilyDocuments
{
    public function __construct(
        private readonly Guardianship $guardianship,
        private readonly DocumentReader $reader,
        private readonly SchoolProfile $school,
    ) {}

    /**
     * @return array{child: array{id: string, first_name: string, name: string}, documents: list<array{id: string, title: string, type: string, date: string|null, added_on: string, file_type: string}>, school_phone: string|null}
     */
    public function child(User $parent, string $learnerId): array
    {
        $child = $this->guardianship->child($parent, $learnerId);

        if ($child === null) {
            throw new NotFoundHttpException;
        }

        $documents = self::sharedOf($learnerId);

        if ($documents === []) {
            throw new NotFoundHttpException;
        }

        return ['child' => ['id' => $child['id'], 'first_name' => $child['first_name'], 'name' => $child['name']], 'documents' => $documents,
            'school_phone' => Phone::forPeople($this->school->phone())];
    }

    /**
     * Opens one shared document for the parent. The kernel writes the read on the document's history.
     *
     * @return array{name: string, mime: string, size: int, bytes: Generator<int, string>}
     */
    public function open(User $parent, string $learnerId, string $documentId): array
    {
        if ($this->guardianship->child($parent, $learnerId) === null || ! in_array($documentId, array_column(self::sharedOf($learnerId), 'id'), true)) {
            throw new NotFoundHttpException;
        }

        $document = Document::query()->live()->find($documentId);

        if (! $document instanceof Document) {
            throw new NotFoundHttpException;
        }

        $opened = $this->reader->open($parent, $document);

        return ['name' => $opened['file']->name, 'mime' => $opened['file']->mime, 'size' => $opened['file']->size, 'bytes' => $opened['bytes']];
    }

    /**
     * The live documents of a learner whose type families see, newest first.
     *
     * @return list<array{id: string, title: string, type: string, date: string|null, added_on: string, file_type: string}>
     */
    public static function sharedOf(string $learnerId): array
    {
        $staff = LearnerDocumentAccess::Staff;
        $out = [];

        foreach (DB::table('learner_documents as l')->join('learner_document_types as t', 't.id', '=', 'l.type_id')->join('documents as d', 'd.id', '=', 'l.document_id')
            ->leftJoin('document_files as f', 'f.id', '=', 'd.current_file_id')
            ->where('l.learner_id', $learnerId)->where('t.families', true)->where('t.access', $staff->value)->where('d.kind', $staff->kind()->key())
            ->whereNull('d.trashed_at')->whereNull('d.purged_at')->orderByDesc('d.created_at')
            ->get(['d.id', 'd.title', 't.name', 'l.document_date', 'd.created_at', 'f.mime_type']) as $r) {
            $mime = Row::maybeText($r, 'mime_type') ?? '';
            $out[] = ['id' => Row::text($r, 'id'), 'title' => Row::text($r, 'title'), 'type' => Row::text($r, 'name'), 'date' => Row::maybeText($r, 'document_date'),
                'added_on' => substr(Row::text($r, 'created_at'), 0, 10), 'file_type' => $mime === 'application/pdf' ? 'pdf' : (str_starts_with($mime, 'image/') ? 'photo' : 'file')];
        }

        return $out;
    }
}
