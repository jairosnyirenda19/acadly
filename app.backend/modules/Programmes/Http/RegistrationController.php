<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Modules\Programmes\Application\Actions\RegistrationActions;
use Acadly\Modules\Programmes\Application\Actions\StudentActions;
use Acadly\Modules\Programmes\Application\Place;
use Acadly\Modules\Programmes\Application\Registration\RegistrationSlip;
use Acadly\Modules\Programmes\Application\Registration\RegistrationViews;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Acadly\Modules\Programmes\Contracts\StudentStatus;
use Acadly\Modules\Programmes\Contracts\StudyMode;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Semester registration over the API (D-098). Each route first checks the permission at the division. */
final class RegistrationController
{
    /**
     * The students in programmes, with their registration for a semester of this year.
     *
     * @response array{periods: list<array{id: string, name: string, starts_on: string, ends_on: string, position: int}>, period: array{id: string, name: string, starts_on: string, ends_on: string, position: int}|null, students: list<array{id: string, learner_id: string, code: string, name: string, programme: array{id: string, code: string}, curriculum: string, year: int, mode: string, status: string, registration: array{id: string, state: string}|null}>, candidates: list<array{id: string, code: string, name: string}>, programmes: list<array{id: string, code: string, name: string, years: int, curriculum: string|null}>, can: array{register: bool, approve: bool, allow_fees: bool}}
     */
    public function students(Request $request, string $division, Place $place, RegistrationViews $views): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::View);
        [$periods, $period] = self::period($request, $place, $division);
        $can = self::can($place, $who, $division);
        $data = $views->students($division, $period['id'] ?? null);

        return response()->json(['periods' => $periods, 'period' => $period, ...$data, 'candidates' => $can['register'] ? $data['candidates'] : [], 'can' => $can]);
    }

    /**
     * Places a student in a programme, on its newest released curriculum.
     *
     * @response array{id: string}
     */
    public function place(Request $request, string $division, Place $place, StudentActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Register);
        $request->validate(['learner_id' => ['required', 'uuid'], 'programme_id' => ['required', 'uuid'], 'entry_year' => ['required', 'integer', 'min:1', 'max:9'],
            'mode' => ['required', Rule::enum(StudyMode::class)]]);

        return response()->json(['id' => $actions->place($who, $division, $request->string('learner_id')->toString(), $request->string('programme_id')->toString(),
            $request->integer('entry_year'), StudyMode::from($request->string('mode')->toString()))]);
    }

    /**
     * A new status for a student: leave of absence, withdrawn, or active again. The earlier row stays.
     *
     * @response array{id: string}
     */
    public function status(Request $request, string $division, string $id, Place $place, StudentActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Register);
        $request->validate(['status' => ['required', Rule::in([StudentStatus::Active->value, StudentStatus::Leave->value, StudentStatus::Withdrawn->value])],
            'reason' => ['required', 'string', 'max:255']]);

        return response()->json(['id' => $actions->change($who, $division, $id, StudentStatus::from($request->string('status')->toString()), null, $request->string('reason')->toString())]);
    }

    /**
     * The registration desk of a semester: its dates, every registration and the students not started.
     *
     * @response array{periods: list<array{id: string, name: string, starts_on: string, ends_on: string, position: int}>, period: array{id: string, name: string, starts_on: string, ends_on: string, position: int}|null, window: array{opens_on: string, closes_on: string, late_until: string, add_drop_until: string, late_fee: int, fees_share: int, started_at: string|null}|null, phase: string, add_drop: bool, rows: list<array{id: string, learner_id: string, code: string, name: string, programme: string, year: int, courses: int, credits: int, state: string, late: bool, problems: int, fees_more: int}>, not_started: list<array{learner_id: string, code: string, name: string, programme: string, year: int}>, inactive: int, can: array{register: bool, approve: bool, allow_fees: bool}}
     */
    public function desk(Request $request, string $division, Place $place, RegistrationViews $views): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::View);
        [$periods, $period] = self::period($request, $place, $division);
        $empty = ['window' => null, 'phase' => 'none', 'add_drop' => false, 'rows' => [], 'not_started' => [], 'inactive' => 0];

        return response()->json(['periods' => $periods, 'period' => $period, ...($period !== null ? $views->desk($division, $period['id']) : $empty), 'can' => self::can($place, $who, $division)]);
    }

    /**
     * Sets a semester's registration dates, its late fee and the share of fees needed.
     *
     * @response array{saved: bool}
     */
    public function saveWindow(Request $request, string $division, string $period, Place $place, RegistrationActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Register);
        $request->validate(['opens_on' => ['required', 'date_format:Y-m-d'], 'closes_on' => ['required', 'date_format:Y-m-d'], 'late_until' => ['required', 'date_format:Y-m-d'],
            'add_drop_until' => ['required', 'date_format:Y-m-d'], 'late_fee' => ['required', 'integer', 'min:0'], 'fees_share' => ['required', 'integer', 'min:0', 'max:100']]);
        $actions->saveWindow($who, $division, $period, ['opens_on' => $request->string('opens_on')->toString(), 'closes_on' => $request->string('closes_on')->toString(),
            'late_until' => $request->string('late_until')->toString(), 'add_drop_until' => $request->string('add_drop_until')->toString(),
            'late_fee' => $request->integer('late_fee'), 'fees_share' => $request->integer('fees_share')]);

        return response()->json(['saved' => true]);
    }

    /**
     * Starts the semester: a registration for every active student, with the curriculum's courses filled in.
     *
     * @response array{opened: int, sent: int, completed: int}
     */
    public function start(Request $request, string $division, string $period, Place $place, RegistrationActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Register);

        return response()->json($actions->startSemester($who, $division, $period));
    }

    /**
     * Opens one student's registration for the semester.
     *
     * @response array{id: string}
     */
    public function open(Request $request, string $division, string $period, Place $place, RegistrationActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Register);
        $request->validate(['learner_id' => ['required', 'uuid']]);

        return response()->json(['id' => $actions->open($who, $division, $period, $request->string('learner_id')->toString())]);
    }

    /**
     * Approves registrations waiting for approval; those whose fees are cleared are registered at once.
     *
     * @response array{approved: int, registered: int}
     */
    public function approve(Request $request, string $division, string $period, Place $place, RegistrationActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Approve);
        $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['uuid']]);

        return response()->json($actions->approve($who, $division, self::strings($request->input('ids'))));
    }

    /**
     * Completes every approved registration of the semester whose fees are now cleared.
     *
     * @response array{registered: int}
     */
    public function register(Request $request, string $division, string $period, Place $place, RegistrationActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Register);
        $request->validate(['ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['uuid']]);

        return response()->json(['registered' => $actions->register($who, $division, $period, $request->has('ids') ? self::strings($request->input('ids')) : null)]);
    }

    /**
     * One registration: the student, the courses, what can be chosen, the checks, the fees and the steps open now.
     *
     * @response array{id: string, state: string, late: bool, version: int, period: string, period_id: string, student: array{learner_id: string, code: string, name: string, programme: array{id: string, code: string, name: string}, curriculum: string, year: int, semester: int, mode: string}, courses: list<array{id: string, offering_id: string, course: array{id: string, code: string, title: string}, kind: string, elective_group_id: string|null, group: string, credits: int, needs: list<string>}>, core: list<string>, groups: list<array{id: string, label: string, choose: int, courses: list<string>}>, offerings: list<array{id: string, course: array{id: string, code: string, title: string}, group: string, credits: int, seats_left: int|null}>, checks: array{problems: list<array{kind: string, text: string}>, load: int, min: int, max: int}, fees: array{known: bool, share: int, owed: int, paid: int, needed: int, more: int, cleared: bool, allowed: array{by: string, reason: string, at: string}|null}, steps: list<array{step: string, title: string, outcomes: list<array{outcome: string, title: string, requires_reason: bool}>, permitted: bool, blocked_reason: string|null, remedy: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, phase: string, add_drop: bool, can: array{register: bool, approve: bool, allow_fees: bool}}
     */
    public function show(Request $request, string $division, string $id, Place $place, RegistrationActions $actions, RegistrationViews $views): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::View);

        return response()->json([...$views->registration($who, $actions->registration($division, $id)), 'can' => self::can($place, $who, $division)]);
    }

    /**
     * Sets the courses of a registration being chosen: one group of each course.
     *
     * @response array{saved: bool}
     */
    public function saveCourses(Request $request, string $division, string $id, Place $place, RegistrationActions $actions): JsonResponse
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::Register);
        $request->validate(['offerings' => ['present', 'array', 'max:20'], 'offerings.*' => ['uuid']]);
        $actions->saveCourses($who, $division, $id, self::strings($request->input('offerings')));

        return response()->json(['saved' => true]);
    }

    /**
     * A step of the registration: send for approval, return with a reason, allow without the fees needed, change courses in add and drop, or withdraw.
     *
     * @response array{done: bool}
     */
    public function act(Request $request, string $division, string $id, string $action, Place $place, RegistrationActions $actions): JsonResponse
    {
        $who = self::user($request);
        $permission = match ($action) {
            'return' => ProgrammesPermission::Approve,
            'allow-fees' => ProgrammesPermission::AllowFees,
            default => ProgrammesPermission::Register,
        };
        $place->division($who, $division, $permission);

        if ($action !== 'submit') {
            $request->validate(['reason' => ['required', 'string', 'max:255']]);
        }
        $reason = $request->string('reason')->toString();

        match ($action) {
            'submit' => $actions->submit($who, $division, $id),
            'return' => $actions->returnBack($who, $division, $id, $reason),
            'allow-fees' => $actions->allowFees($who, $division, $id, $reason),
            'change' => $actions->change($who, $division, $id, $reason),
            default => $actions->withdraw($who, $division, $id, $reason),
        };

        return response()->json(['done' => true]);
    }

    /**
     * A student's programme and semester registrations, for the learner record. Not found when the student is in no programme.
     *
     * @response array{division_id: string, programme: array{code: string, name: string}, curriculum: string, year: int, mode: string, status: string, history: list<array{year: int, status: string, reason: string|null, at: string}>, registrations: list<array{id: string, period: string, state: string, year: int, semester: int, version: int, late: bool, courses: list<array{code: string, title: string, kind: string, credits: int}>}>}
     */
    public function learner(Request $request, string $id, Place $place, RegistrationViews $views): JsonResponse
    {
        $who = self::user($request);
        $data = $views->learner($id);

        if ($data === null || ! $place->allows($who, $data['division_id'], ProgrammesPermission::View)) {
            throw new NotFoundHttpException;
        }

        return response()->json($data);
    }

    /** The registration slip as an A4 PDF, with its check code (D-098). */
    public function slip(Request $request, string $division, string $id, Place $place, RegistrationActions $actions, RegistrationSlip $slip): Response
    {
        $who = self::user($request);
        $place->division($who, $division, ProgrammesPermission::View);
        $doc = $slip->render($who, $actions->registration($division, $id));

        return new Response($doc['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $doc['name']).'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{0: list<array{id: string, name: string, starts_on: string, ends_on: string, position: int}>, 1: array{id: string, name: string, starts_on: string, ends_on: string, position: int}|null} */
    private static function period(Request $request, Place $place, string $division): array
    {
        $periods = $place->periods($division);
        $asked = $request->string('period')->toString();
        $today = now()->toDateString();
        $period = null;

        foreach ($periods as $p) {
            if ($p['id'] === $asked) {
                $period = $p;
            }
        }

        // Else the semester in progress, or the next one.
        foreach ($asked === '' || $period === null ? $periods : [] as $p) {
            if ($period === null && $p['ends_on'] >= $today) {
                $period = $p;
            }
        }

        return [$periods, $period ?? ($periods[count($periods) - 1] ?? null)];
    }

    /** @return array{register: bool, approve: bool, allow_fees: bool} */
    private static function can(Place $place, User $who, string $division): array
    {
        return ['register' => $place->allows($who, $division, ProgrammesPermission::Register), 'approve' => $place->allows($who, $division, ProgrammesPermission::Approve),
            'allow_fees' => $place->allows($who, $division, ProgrammesPermission::AllowFees)];
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
}
