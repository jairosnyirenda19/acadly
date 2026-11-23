<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Modules\Classroom\Application\Actions\CourseActions;
use Acadly\Modules\Classroom\Application\Actions\TaskActions;
use Acadly\Modules\Classroom\Application\Actions\WorkActions;
use Acadly\Modules\Classroom\Application\ClassroomParts;
use Acadly\Modules\Classroom\Application\ClassroomViews;
use Acadly\Modules\Classroom\Application\Courses;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Classroom in the console (D-104): the person's courses, a course page,
 * assignments and exam papers, every hand-in, marking, release and
 * continuous assessment. Each route checks the division first.
 */
final class ClassroomController
{
    public function __construct(private readonly Courses $courses) {}

    /**
     * The courses the person runs, or every course of the division for those who see them.
     *
     * @response array{period: array{id: string, name: string}|null, courses: list<array{id: string, code: string, title: string, group: string, department: string, lecturers: list<string>, students: int, tasks: int, to_mark: int, teaches: bool}>}
     */
    public function courses(Request $request, string $division, ClassroomViews $views): JsonResponse
    {
        return response()->json($views->courses(self::user($request), $division));
    }

    /**
     * One course page.
     *
     * @response array{course: array{id: string, code: string, title: string, group: string, department: string, period: string, lecturers: list<string>, students: int}, teaches: bool, share: int, weights: float, resources: list<array{id: string, section: string, title: string, kind: string, url: string|null, document_id: string|null, name: string|null, size: int|null, mime: string|null, shows_from: string|null, pinned: bool, at: string}>, announcements: list<array{id: string, title: string, body: string, sms: bool, by: string, at: string, read: int}>, tasks: list<array{id: string, kind: string, title: string, shows_at: string, due_at: string, late_until: string|null, out_of: int, weight: float, released: bool, accepts: list<string>, max_files: int, max_mb: int, counts: array{registered: int, handed_in: int, late: int, missing: int, open: int, marked: int, to_mark: int}}>, file_types: list<string>, max_files: int, max_mb: int}
     */
    public function course(Request $request, string $division, string $id, ClassroomViews $views): JsonResponse
    {
        return response()->json($views->course(self::user($request), $division, $id));
    }

    /** @response 201 array{id: string} */
    public function addResource(Request $request, string $division, string $id, CourseActions $actions): JsonResponse
    {
        $this->courses->run(self::user($request), $division, $id);
        $request->validate(['section' => ['required', 'string', 'max:80'], 'title' => ['nullable', 'string', 'max:160'], 'url' => ['nullable', 'string', 'max:500'],
            'shows_from' => ['nullable', 'date_format:Y-m-d'], 'pinned' => ['nullable', 'boolean'], 'file' => ['nullable', 'file']]);
        $file = $request->file('file');

        return response()->json(['id' => $actions->addResource(self::user($request), $division, $id, ['section' => $request->string('section')->toString(),
            'title' => $request->string('title')->toString(), 'url' => self::text($request, 'url'), 'shows_from' => self::text($request, 'shows_from'),
            'pinned' => $request->boolean('pinned')], $file instanceof UploadedFile ? $file : null)], 201);
    }

    /** @response array{saved: bool} */
    public function removeResource(Request $request, string $division, string $id, CourseActions $actions): JsonResponse
    {
        $actions->removeResource(self::user($request), $division, $id);

        return response()->json(['saved' => true]);
    }

    /** @response 201 array{id: string} */
    public function announce(Request $request, string $division, string $id, CourseActions $actions): JsonResponse
    {
        $this->courses->run(self::user($request), $division, $id);
        $request->validate(['title' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:4000'], 'sms' => ['nullable', 'boolean']]);

        return response()->json(['id' => $actions->announce(self::user($request), $division, $id, $request->string('title')->toString(), $request->string('body')->toString(),
            $request->boolean('sms'))], 201);
    }

    /** @response array{saved: bool} */
    public function share(Request $request, string $division, string $id, CourseActions $actions): JsonResponse
    {
        $this->courses->run(self::user($request), $division, $id);
        $request->validate(['ca_share' => ['required', 'integer']]);
        $actions->setShare(self::user($request), $division, $id, $request->integer('ca_share'));

        return response()->json(['saved' => true]);
    }

    /** @response 201 array{id: string} */
    public function saveTask(Request $request, string $division, string $id, TaskActions $actions): JsonResponse
    {
        $this->courses->run(self::user($request), $division, $id);
        $request->validate(['id' => ['nullable', 'uuid'], 'kind' => ['required', 'string', 'max:10'], 'title' => ['required', 'string', 'max:160'], 'instructions' => ['nullable', 'string', 'max:20000'],
            'shows_at' => ['required', 'string', 'max:40'], 'due_at' => ['required', 'string', 'max:40'], 'late_until' => ['nullable', 'string', 'max:40'], 'out_of' => ['required', 'integer'],
            'weight' => ['required', 'numeric'], 'accepts' => ['required', 'array'], 'accepts.*' => ['string', 'max:12'], 'max_files' => ['required', 'integer'], 'max_mb' => ['required', 'integer']]);
        $accepts = array_values(array_filter((array) $request->input('accepts', []), is_string(...)));

        return response()->json(['id' => $actions->save(self::user($request), $division, $id, ['id' => self::text($request, 'id'), 'kind' => $request->string('kind')->toString(),
            'title' => $request->string('title')->toString(), 'instructions' => $request->string('instructions')->toString(), 'shows_at' => $request->string('shows_at')->toString(),
            'due_at' => $request->string('due_at')->toString(), 'late_until' => self::text($request, 'late_until'), 'out_of' => $request->integer('out_of'),
            'weight' => $request->float('weight'), 'accepts' => $accepts, 'max_files' => $request->integer('max_files'), 'max_mb' => $request->integer('max_mb')])], 201);
    }

    /** @response 201 array{id: string} */
    public function addTaskFile(Request $request, string $division, string $id, TaskActions $actions): JsonResponse
    {
        $this->courses->enter(self::user($request), $division);
        $request->validate(['file' => ['required', 'file']]);
        $file = $request->file('file');

        return response()->json(['id' => $actions->addFile(self::user($request), $division, $id, $file instanceof UploadedFile ? $file : throw new AuthenticationException)], 201);
    }

    /**
     * Every student's hand-in for one assignment or exam paper.
     *
     * @response array{task: array{id: string, kind: string, title: string, shows_at: string, due_at: string, late_until: string|null, out_of: int, weight: float, released: bool, accepts: list<string>, max_files: int, max_mb: int, instructions: string, files: list<array{id: string, name: string, size: int, mime: string}>}, course: array{id: string, code: string, title: string}, teaches: bool, over: bool, counts: array{registered: int, handed_in: int, late: int, missing: int, open: int, marked: int, to_mark: int}, rows: list<array{learner_id: string, name: string, number: string, state: string, at: string|null, withdrawn_at: string|null, times: int, files: list<array{id: string, name: string, size: int, mime: string}>, mark: array{mark: float, by: string, at: string}|null}>}
     */
    public function task(Request $request, string $division, string $id, ClassroomViews $views): JsonResponse
    {
        return response()->json($views->task(self::user($request), $division, $id));
    }

    /**
     * One student's work, for marking.
     *
     * @response array{task: array{id: string, kind: string, title: string, shows_at: string, due_at: string, late_until: string|null, out_of: int, weight: float, released: bool, accepts: list<string>, max_files: int, max_mb: int}, course: array{id: string, code: string, title: string}, teaches: bool, student: array{learner_id: string, name: string, number: string}, state: string, history: list<array{action: string, late: bool, at: string}>, files: list<array{id: string, name: string, size: int, mime: string}>, mark: array{id: string, mark: float, feedback: string|null, reason: string|null, at: string, by: string, was: float|null, files: list<array{id: string, name: string, size: int, mime: string}>}|null, position: array{index: int, of: int, marked: int, previous: string|null, next: string|null}}
     */
    public function work(Request $request, string $division, string $id, string $learner, ClassroomViews $views): JsonResponse
    {
        return response()->json($views->work(self::user($request), $division, $id, $learner));
    }

    /** @response 201 array{id: string} */
    public function mark(Request $request, string $division, string $id, WorkActions $actions): JsonResponse
    {
        $this->courses->enter(self::user($request), $division);
        $request->validate(['learner_id' => ['required', 'uuid'], 'mark' => ['required', 'numeric'], 'feedback' => ['nullable', 'string', 'max:10000'],
            'reason' => ['nullable', 'string', 'max:255'], 'file' => ['nullable', 'file']]);
        $file = $request->file('file');

        return response()->json(['id' => $actions->mark(self::user($request), $division, $id, $request->string('learner_id')->toString(), $request->float('mark'),
            self::text($request, 'feedback'), self::text($request, 'reason'), $file instanceof UploadedFile ? $file : null)], 201);
    }

    /** @response array{saved: bool} */
    public function release(Request $request, string $division, string $id, WorkActions $actions): JsonResponse
    {
        $actions->release(self::user($request), $division, $id);

        return response()->json(['saved' => true]);
    }

    /**
     * The course's continuous assessment.
     *
     * @response array{course: array{id: string, code: string, title: string}, share: int, tasks: list<array{id: string, kind: string, title: string, shows_at: string, due_at: string, late_until: string|null, out_of: int, weight: float, released: bool, accepts: list<string>, max_files: int, max_mb: int}>, rows: list<array{learner_id: string, name: string, number: string, cells: list<array{mark: float|null, state: string, released: bool}>, total: float, missing: int}>, average: float|null, released: int}
     */
    public function assessment(Request $request, string $division, string $id, ClassroomViews $views): JsonResponse
    {
        return response()->json($views->assessment(self::user($request), $division, $id));
    }

    /**
     * The Coursework tab of the learner record.
     *
     * @response array{semester: string, courses: list<array{code: string, title: string, total: float, share: int, missing: int, tasks: list<array{title: string, kind: string, out_of: int, weight: float, mark: float|null, state: string, released: bool}>}>, missing: int}
     */
    public function learner(Request $request, string $learner, ClassroomParts $parts): JsonResponse
    {
        return response()->json($parts->tab(self::user($request), $learner));
    }

    private static function text(Request $request, string $key): ?string
    {
        $v = $request->input($key);

        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
