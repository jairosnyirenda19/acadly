<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Http;

use Acadly\Core\Enrolment\Application\Actions\LearnerDocumentActions;
use Acadly\Core\Enrolment\Application\LearnerDocuments;
use Acadly\Core\Enrolment\Application\Reach;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Domain\LearnerDocumentAccess;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The documents on a learner's record, and the school's list of their types (D-090). */
final class LearnerDocumentsController
{
    /**
     * The documents on a learner's record that the person may open.
     *
     * @response array{types: list<array{id: string, name: string, access: string, expected: bool, retired: bool}>, documents: list<array{id: string, title: string, type_id: string, access: string, date: string|null, file_type: string, size: int, versions: int, added_at: string, added_by: string|null, thumbnail: bool}>, missing: list<array{type_id: string, name: string}>, can_add: list<string>}
     */
    public function index(Request $request, string $id, LearnerDocuments $documents): JsonResponse
    {
        return response()->json($documents->forLearner(self::user($request), $id));
    }

    /**
     * Adds a document: one file, or the photos of a paper document as its pages.
     *
     * @response 201 array{id: string}
     */
    public function store(Request $request, string $id, LearnerDocumentActions $actions, Reach $reach): JsonResponse
    {
        // Refused before anything about the files is said.
        $reach->learner(self::user($request), $id, EnrolmentPermission::View);
        $request->validate([
            'type_id' => ['required', 'uuid'],
            'title' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'files' => ['required', 'array', 'min:1', 'max:'.LearnerDocumentActions::MAX_PAGES],
            'files.*' => ['file'],
            'preview' => ['nullable', 'file'],
        ]);

        $files = array_values(array_filter((array) $request->file('files'), fn ($f): bool => $f instanceof UploadedFile));
        $preview = $request->file('preview');
        $date = $request->string('date')->toString();
        $document = $actions->add(self::user($request), $id, $request->string('type_id')->toString(), $request->string('title')->toString(),
            $date !== '' ? $date : null, $files, $preview instanceof UploadedFile ? $preview : null);

        return response()->json(['id' => $document->id], 201);
    }

    /**
     * The school's learner document types, with how many documents each has.
     *
     * @response array{types: list<array{id: string, name: string, access: string, expected: bool, retired: bool, on_file: int}>}
     */
    public function types(Request $request, Access $access): JsonResponse
    {
        $access->check(self::user($request), EnrolmentPermission::Manage);
        $counts = [];

        foreach (DB::table('learner_documents as l')->join('documents as d', 'd.id', '=', 'l.document_id')
            ->whereNull('d.trashed_at')->whereNull('d.purged_at')->groupBy('l.type_id')
            ->get(['l.type_id', DB::raw('count(*) as n')]) as $row) {
            $counts[Row::text($row, 'type_id')] = Row::int($row, 'n');
        }

        return response()->json(['types' => array_map(fn (array $t): array => [...$t, 'on_file' => $counts[$t['id']] ?? 0], LearnerDocuments::types())]);
    }

    /**
     * Adds a learner document type.
     *
     * @response 201 array{id: string}
     */
    public function addType(Request $request, LearnerDocumentActions $actions, Access $access): JsonResponse
    {
        return response()->json(['id' => $this->save($request, $actions, $access, null)], 201);
    }

    /**
     * Changes a learner document type.
     *
     * @response array{id: string}
     */
    public function changeType(Request $request, string $id, LearnerDocumentActions $actions, Access $access): JsonResponse
    {
        return response()->json(['id' => $this->save($request, $actions, $access, $id)]);
    }

    /**
     * Retires a learner document type.
     *
     * @response array{id: string}
     */
    public function retireType(Request $request, string $id, LearnerDocumentActions $actions): JsonResponse
    {
        $actions->retireType(self::user($request), $id);

        return response()->json(['id' => $id]);
    }

    private function save(Request $request, LearnerDocumentActions $actions, Access $access, ?string $id): string
    {
        // Refused before anything about the form is said.
        $access->check(self::user($request), EnrolmentPermission::Manage);
        $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'access' => ['required', Rule::enum(LearnerDocumentAccess::class)],
            'expected' => ['required', 'boolean'],
        ]);

        return $actions->saveType(self::user($request), $id, $request->string('name')->toString(),
            LearnerDocumentAccess::from($request->string('access')->toString()), $request->boolean('expected'));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
