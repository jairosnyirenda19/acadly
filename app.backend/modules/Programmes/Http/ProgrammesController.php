<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Modules\Programmes\Application\Actions\CourseActions;
use Acadly\Modules\Programmes\Application\Actions\OfferingActions;
use Acadly\Modules\Programmes\Application\Actions\ProgrammeActions;
use Acadly\Modules\Programmes\Application\Actions\StructureActions;
use Acadly\Modules\Programmes\Application\Place;
use Acadly\Modules\Programmes\Application\ProgrammesViews;
use Acadly\Modules\Programmes\Contracts\Award;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Programmes over the API (D-097). Each route first checks the permission at the division. */
final class ProgrammesController
{
    /**
     * Faculties, departments and programmes of a division.
     *
     * @response array{faculties: list<array{id: string, name: string, code: string, retired: bool, departments: list<array{id: string, name: string, code: string, prefixes: list<string>, head: array{id: string, name: string}|null, retired: bool, programmes: int, courses: int, staff: int}>}>, programmes: list<array{id: string, department_id: string, code: string, name: string, award: string, years: int, credits: int, open: bool, retired: bool, released: bool}>, periods: list<array{id: string, name: string, starts_on: string, ends_on: string, position: int}>, staff: list<array{id: string, name: string}>, can: array{manage: bool, offer: bool}}
     */
    public function structure(Request $request, string $division, Place $place): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::View);
        $manage = $place->allows($who, $division, ProgrammesPermission::Manage);

        return response()->json([...ProgrammesViews::structure($division), 'periods' => $place->periods($division), 'staff' => $manage ? ProgrammesViews::staff() : [],
            'can' => ['manage' => $manage, 'offer' => $place->allows($who, $division, ProgrammesPermission::Offer)]]);
    }

    /**
     * Saves the faculties, in order.
     *
     * @response array{saved: bool}
     */
    public function saveFaculties(Request $request, string $division, Place $place, StructureActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Manage);
        $request->validate(['faculties' => ['present', 'array', 'max:40'], 'faculties.*.id' => ['nullable', 'uuid'], 'faculties.*.name' => ['required', 'string', 'max:120'],
            'faculties.*.code' => ['required', 'string', 'max:12'], 'faculties.*.retired' => ['required', 'boolean']]);
        $faculties = [];

        foreach ((array) $request->input('faculties') as $f) {
            $faculties[] = ['id' => self::maybe($f, 'id'), 'name' => self::text($f, 'name'), 'code' => self::text($f, 'code'), 'retired' => self::flag($f, 'retired')];
        }

        $actions->saveFaculties($who, $division, $faculties);

        return response()->json(['saved' => true]);
    }

    /**
     * Adds or changes a department.
     *
     * @response array{id: string}
     */
    public function saveDepartment(Request $request, string $division, Place $place, StructureActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Manage);
        $request->validate(['id' => ['nullable', 'uuid'], 'faculty_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:120'], 'code' => ['required', 'string', 'max:12'],
            'prefixes' => ['present', 'array', 'max:12'], 'prefixes.*' => ['string', 'max:5'], 'head_user_id' => ['nullable', 'uuid'], 'retired' => ['required', 'boolean']]);

        return response()->json(['id' => $actions->saveDepartment($who, $division, ['id' => $request->string('id')->toString() ?: null, 'faculty_id' => $request->string('faculty_id')->toString(),
            'name' => $request->string('name')->toString(), 'code' => $request->string('code')->toString(), 'prefixes' => self::strings($request->input('prefixes')),
            'head_user_id' => $request->string('head_user_id')->toString() ?: null, 'retired' => $request->boolean('retired')])]);
    }

    /**
     * The course catalogue of a division.
     *
     * @response array{courses: list<array{id: string, code: string, title: string, department_id: string, department: string, credits: int, lecture_hours: int, tutorial_hours: int, practical_hours: int, needs: list<array{id: string, code: string}>, used_in: list<string>, retired: bool, locked: bool}>, departments: list<array{id: string, name: string, prefixes: list<string>}>, can: array{manage: bool}}
     */
    public function catalogue(Request $request, string $division, Place $place): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::View);

        return response()->json([...ProgrammesViews::catalogue($division), 'can' => ['manage' => $place->allows($who, $division, ProgrammesPermission::Manage)]]);
    }

    /**
     * Adds or changes a course.
     *
     * @response array{id: string}
     */
    public function saveCourse(Request $request, string $division, Place $place, CourseActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Manage);
        $request->validate(['id' => ['nullable', 'uuid'], 'department_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:16'], 'title' => ['required', 'string', 'max:160'],
            'credits' => ['required', 'integer'], 'lecture_hours' => ['required', 'integer'], 'tutorial_hours' => ['required', 'integer'], 'practical_hours' => ['required', 'integer'],
            'needs' => ['present', 'array', 'max:12'], 'needs.*' => ['uuid'], 'retired' => ['required', 'boolean']]);

        return response()->json(['id' => $actions->save($who, $division, ['id' => $request->string('id')->toString() ?: null, 'department_id' => $request->string('department_id')->toString(),
            'code' => $request->string('code')->toString(), 'title' => $request->string('title')->toString(), 'credits' => $request->integer('credits'),
            'lecture_hours' => $request->integer('lecture_hours'), 'tutorial_hours' => $request->integer('tutorial_hours'), 'practical_hours' => $request->integer('practical_hours'),
            'needs' => self::strings($request->input('needs')), 'retired' => $request->boolean('retired')])]);
    }

    /**
     * Adds or changes a programme. A new programme starts with a draft curriculum.
     *
     * @response array{id: string}
     */
    public function saveProgramme(Request $request, string $division, Place $place, ProgrammeActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Manage);
        $request->validate(['id' => ['nullable', 'uuid'], 'department_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:12'], 'name' => ['required', 'string', 'max:160'],
            'award' => ['required', Rule::enum(Award::class)], 'years' => ['required', 'integer'], 'semesters' => ['required', 'integer'], 'credits' => ['required', 'integer'],
            'min_load' => ['required', 'integer'], 'max_load' => ['required', 'integer'], 'open' => ['required', 'boolean'], 'retired' => ['required', 'boolean']]);

        return response()->json(['id' => $actions->save($who, $division, ['id' => $request->string('id')->toString() ?: null, 'department_id' => $request->string('department_id')->toString(),
            'code' => $request->string('code')->toString(), 'name' => $request->string('name')->toString(), 'award' => Award::from($request->string('award')->toString()),
            'years' => $request->integer('years'), 'semesters' => $request->integer('semesters'), 'credits' => $request->integer('credits'),
            'min_load' => $request->integer('min_load'), 'max_load' => $request->integer('max_load'), 'open' => $request->boolean('open'), 'retired' => $request->boolean('retired')])]);
    }

    /**
     * One programme with its curriculum versions; the version asked for, the draft, or the newest released.
     *
     * @response array{programme: array{id: string, department_id: string, department: string, faculty: string, code: string, name: string, award: string, years: int, semesters: int, credits: int, min_load: int, max_load: int, open: bool, retired: bool}, versions: list<array{id: string, label: string, released_at: string|null}>, version: array{id: string, label: string, released: bool, groups: list<array{id: string, year: int, semester: int, label: string, choose: int}>, courses: list<array{course_id: string, code: string, title: string, credits: int, retired: bool, year: int, semester: int, group_id: string|null, needs: list<array{id: string, code: string}>}>, problems: list<array<string, string|int>>, years: array<int, int>, slots: array<string, int>, total: int}|null, can: array{manage: bool}}
     */
    public function programme(Request $request, string $division, string $id, Place $place): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::View);
        $data = ProgrammesViews::programme($division, $id, $request->string('version')->toString() ?: null) ?? throw new NotFoundHttpException;

        return response()->json([...$data, 'can' => ['manage' => $place->allows($who, $division, ProgrammesPermission::Manage)]]);
    }

    /**
     * Starts a new draft version, copied from the newest released one.
     *
     * @response array{id: string}
     */
    public function newVersion(Request $request, string $division, string $id, Place $place, ProgrammeActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Manage);

        return response()->json(['id' => $actions->newVersion($who, $division, $id)]);
    }

    /**
     * Saves a draft version's elective groups and courses.
     *
     * @response array{saved: bool}
     */
    public function saveDraft(Request $request, string $division, string $id, Place $place, ProgrammeActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Manage);
        $request->validate(['groups' => ['present', 'array', 'max:60'], 'groups.*.key' => ['required', 'string', 'max:40'], 'groups.*.year' => ['required', 'integer'],
            'groups.*.semester' => ['required', 'integer'], 'groups.*.label' => ['required', 'string', 'max:40'], 'groups.*.choose' => ['required', 'integer'],
            'courses' => ['present', 'array', 'max:400'], 'courses.*.course_id' => ['required', 'uuid'], 'courses.*.year' => ['required', 'integer'],
            'courses.*.semester' => ['required', 'integer'], 'courses.*.group' => ['nullable', 'string', 'max:40']]);
        $groups = [];
        $courses = [];

        foreach ((array) $request->input('groups') as $g) {
            $groups[] = ['key' => self::text($g, 'key'), 'year' => self::int($g, 'year'), 'semester' => self::int($g, 'semester'), 'label' => self::text($g, 'label'), 'choose' => self::int($g, 'choose')];
        }
        foreach ((array) $request->input('courses') as $c) {
            $courses[] = ['course_id' => self::text($c, 'course_id'), 'year' => self::int($c, 'year'), 'semester' => self::int($c, 'semester'), 'group' => self::maybe($c, 'group')];
        }

        $actions->saveDraft($who, $division, $id, $groups, $courses);

        return response()->json(['saved' => true]);
    }

    /**
     * Releases a draft version that passes every check.
     *
     * @response array{released: bool}
     */
    public function release(Request $request, string $division, string $id, Place $place, ProgrammeActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Manage);
        $actions->release($who, $division, $id);

        return response()->json(['released' => true]);
    }

    /**
     * A semester's offerings: this year's semesters, the one asked for (else the first), its groups and what is still missing.
     *
     * @response array{periods: list<array{id: string, name: string, starts_on: string, ends_on: string, position: int}>, period: array{id: string, name: string, starts_on: string, ends_on: string, position: int}|null, offerings: list<array{id: string, course: array{id: string, code: string, title: string}, group: string, limit: int|null, lecturers: list<array{id: string, name: string}>, assistants: list<array{id: string, name: string}>, ready: bool, taken: int}>, missing: int, staff: list<array{id: string, name: string}>, can: array{offer: bool}}
     */
    public function offerings(Request $request, string $division, Place $place): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::View);
        $periods = $place->periods($division);
        $asked = $request->string('period')->toString();
        $period = null;

        foreach ($periods as $p) {
            if ($p['id'] === $asked) {
                $period = $p;
            }
        }
        $period ??= $periods[0] ?? null;
        $offer = $place->allows($who, $division, ProgrammesPermission::Offer);

        return response()->json(['periods' => $periods, 'period' => $period,
            'offerings' => $period !== null ? ProgrammesViews::offerings($division, $period['id']) : [],
            'missing' => $period !== null ? count(ProgrammesViews::missingCourses($division, $period['id'], $period['position'])) : 0,
            'staff' => $offer ? ProgrammesViews::staff() : [], 'can' => ['offer' => $offer]]);
    }

    /**
     * Adds group A of every course the semester needs and does not have yet.
     *
     * @response array{added: int}
     */
    public function prepare(Request $request, string $division, string $period, Place $place, OfferingActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Offer);

        return response()->json(['added' => $actions->prepare($who, $division, $period)]);
    }

    /**
     * Adds the next group of a course.
     *
     * @response array{id: string}
     */
    public function addGroup(Request $request, string $division, string $period, Place $place, OfferingActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Offer);
        $request->validate(['course_id' => ['required', 'uuid']]);

        return response()->json(['id' => $actions->addGroup($who, $division, $period, $request->string('course_id')->toString())]);
    }

    /**
     * Saves a group's size limit, lecturers and assistants.
     *
     * @response array{saved: bool}
     */
    public function saveOffering(Request $request, string $division, string $id, Place $place, OfferingActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Offer);
        $request->validate(['limit' => ['nullable', 'integer'], 'lecturers' => ['present', 'array', 'max:6'], 'lecturers.*' => ['uuid'],
            'assistants' => ['present', 'array', 'max:6'], 'assistants.*' => ['uuid']]);
        $actions->save($who, $division, $id, $request->input('limit') !== null ? $request->integer('limit') : null,
            self::strings($request->input('lecturers')), self::strings($request->input('assistants')));

        return response()->json(['saved' => true]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value)) : [];
    }

    private static function text(mixed $row, string $key): string
    {
        return is_array($row) && is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';
    }

    private static function int(mixed $row, string $key): int
    {
        return is_array($row) && is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
    }

    private static function flag(mixed $row, string $key): bool
    {
        return is_array($row) && in_array($row[$key] ?? false, [true, 1, '1', 'true'], true);
    }

    private static function maybe(mixed $row, string $key): ?string
    {
        $text = self::text($row, $key);

        return $text === '' ? null : $text;
    }
}
