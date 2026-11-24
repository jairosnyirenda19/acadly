<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDocument;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Domain\LearnerDocumentAccess;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Documents\DocumentReader;
use Acadly\Kernel\Documents\Thumbnails;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * The documents on a learner's record, as one person may see them (D-090).
 * A document the person may not open is not in the list, and nor is a
 * missing document of a type the person could not open.
 */
final class LearnerDocuments
{
    public function __construct(
        private readonly Reach $reach,
        private readonly DocumentReader $reader,
        private readonly Access $access,
        private readonly Thumbnails $thumbnails,
    ) {}

    /**
     * @return array{
     *     types: list<array{id: string, name: string, access: string, expected: bool, families: bool, retired: bool}>,
     *     documents: list<array{id: string, title: string, type_id: string, access: string, date: string|null, file_type: string, size: int, versions: int, added_at: string, added_by: string|null, thumbnail: bool}>,
     *     missing: list<array{type_id: string, name: string}>,
     *     can_add: list<string>
     * }
     */
    public function forLearner(User $viewer, string $learnerId): array
    {
        ['at' => $at] = $this->reach->learner($viewer, $learnerId, EnrolmentPermission::View);
        $types = self::types();
        $kinds = array_map(fn (LearnerDocumentAccess $a): string => $a->kind()->key(), LearnerDocumentAccess::cases());
        $documents = $this->reader->ownedBy($viewer, 'learner', $learnerId)->filter(fn (Document $d): bool => in_array($d->kind, $kinds, true));
        $ids = $documents->pluck('id')->all();
        $meta = DB::table('learner_documents')->whereIn('document_id', $ids)->get(['document_id', 'type_id', 'document_date'])->keyBy('document_id');
        $files = DB::table('document_files as f')->leftJoin('users as u', 'u.id', '=', 'f.uploaded_by')
            ->whereIn('f.document_id', $ids)->orderBy('f.version_no')
            ->get(['f.id', 'f.document_id', 'f.mime_type', 'f.byte_size', 'f.uploaded_at', 'u.name as by'])->groupBy('document_id');
        $pictures = $this->thumbnails->present(array_values(array_filter($documents->pluck('current_file_id')->all(), 'is_string')));
        $out = [];
        $held = [];

        foreach ($documents as $document) {
            $row = $meta->get($document->id);
            $versions = $files->get($document->id);

            if ($row === null || $versions === null || $versions->isEmpty()) {
                continue;
            }

            $first = $versions->first();
            $current = $versions->firstWhere('id', $document->current_file_id) ?? $versions->last();
            $typeId = Row::text($row, 'type_id');
            $held[$typeId] = true;
            $out[] = [
                'id' => $document->id,
                'title' => $document->title,
                'type_id' => $typeId,
                'access' => self::accessOf($document->kind),
                'date' => Row::maybeText($row, 'document_date'),
                'file_type' => self::fileType(Row::text($current, 'mime_type')),
                'size' => Row::int($current, 'byte_size'),
                'versions' => $versions->count(),
                'added_at' => Row::text($first, 'uploaded_at'),
                'added_by' => Row::maybeText($first, 'by'),
                'thumbnail' => $document->kind !== EnrolmentDocument::RecordConfidential->value && isset($pictures[(string) $document->current_file_id]),
            ];
        }

        $missing = [];

        foreach ($types as $type) {
            $level = LearnerDocumentAccess::from($type['access']);

            if ($type['expected'] && ! $type['retired'] && ! isset($held[$type['id']]) && $this->may($viewer, $level, $at, false)) {
                $missing[] = ['type_id' => $type['id'], 'name' => $type['name']];
            }
        }

        $canAdd = array_values(array_map(fn (LearnerDocumentAccess $a): string => $a->value,
            array_filter(LearnerDocumentAccess::cases(), fn (LearnerDocumentAccess $a): bool => $this->may($viewer, $a, $at, true))));

        // A retired type stays in the list only while this learner has documents of it.
        $types = array_values(array_filter($types, fn (array $t): bool => ! $t['retired'] || isset($held[$t['id']])));

        return ['types' => $types, 'documents' => $out, 'missing' => $missing, 'can_add' => $canAdd];
    }

    /** @return list<array{id: string, name: string, access: string, expected: bool, families: bool, retired: bool}> */
    public static function types(): array
    {
        $types = [];

        foreach (DB::table('learner_document_types')->orderBy('position')->get(['id', 'name', 'access', 'expected', 'families', 'retired_at']) as $t) {
            $types[] = [
                'id' => Row::text($t, 'id'),
                'name' => Row::text($t, 'name'),
                'access' => Row::text($t, 'access'),
                'expected' => (bool) ($t->expected ?? false),
                'families' => (bool) ($t->families ?? false),
                'retired' => ($t->retired_at ?? null) !== null,
            ];
        }

        return $types;
    }

    private function may(User $viewer, LearnerDocumentAccess $level, ScopeRef $at, bool $write): bool
    {
        $kind = $level->kind();

        return $this->access->allows($viewer, $write ? $kind->writePermission() : $kind->readPermission(), $at);
    }

    private static function accessOf(string $kind): string
    {
        foreach (LearnerDocumentAccess::cases() as $level) {
            if ($level->kind()->key() === $kind) {
                return $level->value;
            }
        }

        return LearnerDocumentAccess::Office->value;
    }

    private static function fileType(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            default => 'file',
        };
    }
}
