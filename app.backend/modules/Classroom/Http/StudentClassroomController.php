<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Http;

use Acadly\Kernel\Documents\DocumentReader;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Classroom\Application\Actions\CourseActions;
use Acadly\Modules\Classroom\Application\Actions\WorkActions;
use Acadly\Modules\Classroom\Application\StudentClassroom;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Classroom in the student portal (D-104). Every route is the signed-in student's own. */
final class StudentClassroomController
{
    public function __construct(private readonly StudentClassroom $classroom) {}

    /**
     * The student's courses this semester.
     *
     * @response array{courses: list<array{id: string, code: string, title: string, lecturers: list<string>, due: int, new: int}>}
     */
    public function courses(Request $request): JsonResponse
    {
        return response()->json(['courses' => $this->classroom->courses($this->user($request))]);
    }

    /**
     * One course: work, announcements, resources and marks.
     *
     * @response array{course: array{id: string, code: string, title: string, lecturers: list<string>}, tasks: list<array{id: string, kind: string, title: string, shows_at: string, due_at: string, late_until: string|null, out_of: int, weight: float, released: bool, accepts: list<string>, max_files: int, max_mb: int, state: string, mark: float|null}>, coming: list<array{id: string, kind: string, title: string, shows_at: string, due_at: string, late_until: string|null, out_of: int, weight: float, released: bool, accepts: list<string>, max_files: int, max_mb: int, state: string, mark: float|null}>, announcements: list<array{id: string, title: string, body: string, by: string, at: string, read: bool}>, resources: list<array{id: string, section: string, title: string, kind: string, url: string|null, document_id: string|null, pinned: bool}>, share: int, ca: float}
     */
    public function course(Request $request, string $id): JsonResponse
    {
        return response()->json($this->classroom->course($this->user($request), $id));
    }

    /**
     * One assignment or exam paper, with the student's own hand-in.
     *
     * @response array{task: array{id: string, kind: string, title: string, shows_at: string, due_at: string, late_until: string|null, out_of: int, weight: float, released: bool, accepts: list<string>, max_files: int, max_mb: int, instructions: string, files: list<array{id: string, name: string, size: int, mime: string}>}, course: array{id: string, code: string, title: string}, state: string, can_hand_in: bool, can_withdraw: bool, files: list<array{id: string, name: string, size: int, mime: string}>, history: list<array{action: string, late: bool, at: string, files: list<string>}>, mark: array{mark: float, feedback: string|null, by: string, at: string, was: float|null, reason: string|null, files: list<array{id: string, name: string, size: int, mime: string}>}|null}
     */
    public function task(Request $request, string $id): JsonResponse
    {
        return response()->json($this->classroom->task($this->user($request), $id));
    }

    /**
     * Work due soon, for Today.
     *
     * @response array{due: list<array{id: string, code: string, title: string, due_at: string, state: string}>}
     */
    public function due(Request $request): JsonResponse
    {
        return response()->json(['due' => $this->classroom->due($this->user($request))]);
    }

    /** @response 201 array{id: string} */
    public function handIn(Request $request, string $id, WorkActions $work): JsonResponse
    {
        $student = $this->user($request);
        $request->validate(['files' => ['required', 'array', 'max:5'], 'files.*' => ['file']]);
        $files = array_values(array_filter((array) $request->file('files', []), fn ($f): bool => $f instanceof UploadedFile));

        return response()->json(['id' => $work->handIn($student, $id, $files)], 201);
    }

    /** @response array{saved: bool} */
    public function withdraw(Request $request, string $id, WorkActions $work): JsonResponse
    {
        $work->withdraw($this->user($request), $id);

        return response()->json(['saved' => true]);
    }

    /** @response array{saved: bool} */
    public function read(Request $request, string $id, CourseActions $actions): JsonResponse
    {
        $user = $this->user($request);
        $offering = DB::table('classroom_announcements')->where('id', $id)->value('offering_id');
        if (! is_string($offering)) {
            throw new NotFoundHttpException;
        }
        $this->classroom->course($user, $offering);
        $actions->read($id, $user->id);

        return response()->json(['saved' => true]);
    }

    /** A file of the student's courses or of their own work, to open or save. */
    public function file(Request $request, string $id, DocumentReader $reader): StreamedResponse
    {
        $user = $this->user($request);
        $opened = $reader->open($user, $reader->find($user, $id));
        $file = $opened['file'];

        return new StreamedResponse(function () use ($opened): void {
            foreach ($opened['bytes'] as $piece) {
                echo $piece;
                flush();
            }
        }, 200, [
            'Content-Type' => $file->mime,
            'Content-Length' => (string) $file->size,
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $file->name, 'document'),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The signed-in student; anyone else finds nothing here. */
    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }
        $this->classroom->me($user);

        return $user;
    }
}
