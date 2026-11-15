<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Http;

use Acadly\Core\Enrolment\Application\LearnerImport;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Admitting learners from a spreadsheet (the approved import screens). */
final class LearnerImportController
{
    public function __construct(private readonly Access $access) {}

    /** A spreadsheet to fill in: the columns, and the classes, relationships and sexes to choose from. */
    public function template(Request $request, LearnerImport $import): BinaryFileResponse
    {
        $viewer = $this->admitter($request);
        $path = tempnam(sys_get_temp_dir(), 'learners').'.xlsx';
        $import->template($viewer, $path);

        return response()->download($path, 'learners-to-admit.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /**
     * Checks every row of a spreadsheet. Nothing is saved: `{file}`.
     *
     * @response array{rows: list<array{row: int, name: string, born: string, sex: string, class: string, contacts: list<string>, number: string|null, state: 'ready'|'double'|'wrong', note: string}>, totals: array{rows: int, ready: int, double: int, wrong: int, classes: int}}
     */
    public function check(Request $request, LearnerImport $import): JsonResponse
    {
        $viewer = $this->admitter($request);
        [$path, $extension] = self::spreadsheet($request);

        return response()->json($import->check($viewer, $path, $extension));
    }

    /**
     * Admits the rows without a problem, and the possible duplicates confirmed by row number: `{file, confirmed[]}`.
     *
     * @response array{admitted: int, left_out: int}
     */
    public function import(Request $request, LearnerImport $import): JsonResponse
    {
        $viewer = $this->admitter($request);
        [$path, $extension] = self::spreadsheet($request);
        $confirmed = $request->input('confirmed');
        $rows = is_array($confirmed) ? array_values(array_map(intval(...), array_filter($confirmed, is_numeric(...)))) : [];

        return response()->json($import->import($viewer, $path, $extension, $rows));
    }

    /** Someone who may admit somewhere; refused before anything about the file is said. */
    private function admitter(Request $request): User
    {
        $user = $request->user();
        $viewer = $user instanceof User ? $user : throw new AuthenticationException;

        foreach ($this->access->grants($viewer) as $grant) {
            if ($grant->permission === EnrolmentPermission::Admit->key()) {
                return $viewer;
            }
        }

        $this->access->check($viewer, EnrolmentPermission::Admit);

        return $viewer;
    }

    /** @return array{string, string} the uploaded file's path and kind */
    private static function spreadsheet(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'max:4096']], [
            'file.required' => 'Choose a file.',
            'file.max' => 'The file is larger than 4 MB.',
        ]);
        $file = $request->file('file');
        $extension = $file instanceof UploadedFile ? mb_strtolower($file->getClientOriginalExtension()) : '';

        if (! $file instanceof UploadedFile || ! in_array($extension, ['xlsx', 'csv'], true)) {
            throw ValidationException::withMessages(['file' => ['Choose an .xlsx or .csv file.']]);
        }

        return [$file->getRealPath() ?: '', $extension];
    }
}
