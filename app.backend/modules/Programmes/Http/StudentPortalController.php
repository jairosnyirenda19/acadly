<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Http;

use Acadly\Kernel\Identity\SignInMethod;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Modules\Fees\Contracts\FeesStanding;
use Acadly\Modules\Programmes\Application\Actions\RegistrationActions;
use Acadly\Modules\Programmes\Application\Actions\StudentAccountActions;
use Acadly\Modules\Programmes\Application\Registration\RegistrationSlip;
use Acadly\Modules\Programmes\Application\StudentPortal;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The student portal (D-101): a student's own records, and setting up the account. */
final class StudentPortalController
{
    /**
     * Sets up a student account with the student number, the code sent to the phone, and a new password; then signs in.
     *
     * @response array{signed_in: bool}
     */
    public function setUp(Request $request, StudentAccountActions $actions): JsonResponse
    {
        $request->validate(['number' => ['required', 'string', 'max:40'], 'code' => ['required', 'string', 'max:10'], 'password' => ['required', 'string', 'max:255']]);
        $user = $actions->setUp($request->string('number')->toString(), $request->string('code')->toString(), $request->string('password')->toString(), (string) $request->ip());
        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
            SignInMethod::markPassword($request);
        }

        return response()->json(['signed_in' => true]);
    }

    /**
     * Who the student is.
     *
     * @response array{name: string, number: string, college: string, programme: array{code: string, name: string}, year: int, semester: string|null, email: string|null, phone: string|null}
     */
    public function me(Request $request, StudentPortal $portal): JsonResponse
    {
        return response()->json($portal->me(self::user($request)));
    }

    /**
     * The student's courses this semester.
     *
     * @response array{semester: string|null, state: string|null, credits: int, courses: list<array{code: string, title: string, credits: int, kind: string, group: string, lecturers: list<string>}>}
     */
    public function courses(Request $request, StudentPortal $portal): JsonResponse
    {
        return response()->json($portal->courses(self::user($request)));
    }

    /**
     * The student's registration for the semester, or null when none is open.
     *
     * @response array{registration: array{id: string, state: string, late: bool, version: int, period: string, period_id: string, student: array{learner_id: string, code: string, name: string, programme: array{id: string, code: string, name: string}, curriculum: string, year: int, semester: int, mode: string}, courses: list<array{id: string, offering_id: string, course: array{id: string, code: string, title: string}, kind: string, elective_group_id: string|null, group: string, credits: int, needs: list<string>}>, core: list<string>, groups: list<array{id: string, label: string, choose: int, courses: list<string>}>, offerings: list<array{id: string, course: array{id: string, code: string, title: string}, group: string, credits: int, seats_left: int|null}>, checks: array{problems: list<array{kind: string, text: string}>, load: int, min: int, max: int}, fees: array{known: bool, share: int, owed: int, paid: int, needed: int, more: int, cleared: bool, allowed: array{by: string, reason: string, at: string}|null}, steps: list<array{step: string, title: string, outcomes: list<array{outcome: string, title: string, requires_reason: bool}>, permitted: bool, blocked_reason: string|null, remedy: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, phase: string, add_drop: bool, window: array{closes_on: string, late_until: string}|null, editable: bool}|null}
     */
    public function registration(Request $request, StudentPortal $portal): JsonResponse
    {
        return response()->json(['registration' => $portal->registration(self::user($request))]);
    }

    /** @response array{saved: bool} */
    public function saveCourses(Request $request, StudentPortal $portal, RegistrationActions $actions): JsonResponse
    {
        // Only a student has a registration of their own: anyone else is not found before anything is read.
        $user = self::user($request);
        $own = $portal->own($user);
        $request->validate(['offerings' => ['present', 'array', 'max:20'], 'offerings.*' => ['uuid']]);
        $actions->saveOwnCourses($user, $own, array_values(array_filter((array) $request->input('offerings'), 'is_string')));

        return response()->json(['saved' => true]);
    }

    /** @response array{sent: bool} */
    public function submit(Request $request, StudentPortal $portal, RegistrationActions $actions): JsonResponse
    {
        $user = self::user($request);
        $actions->submitOwn($user, $portal->own($user));

        return response()->json(['sent' => true]);
    }

    /**
     * The student's own fees, or null when the college keeps no fees here.
     *
     * @response array{fees: array{billed: int, paid: int, left: int, due_now: int, instalments: list<array{label: string, charge: string, due_on: string, amount: int, covered: int, state: string}>, payments: list<array{number: string, received_on: string, amount: int, cancelled: bool}>}|null}
     */
    public function fees(Request $request, StudentPortal $portal, ModuleActivation $modules): JsonResponse
    {
        $student = $portal->student(self::user($request));
        $fees = $modules->isActive('fees') && app()->bound(FeesStanding::class) ? app(FeesStanding::class)->ownFees($student['learner_id']) : null;

        return response()->json(['fees' => $fees]);
    }

    /** The student's own fee statement. */
    public function statement(Request $request, StudentPortal $portal, ModuleActivation $modules): Response
    {
        $user = self::user($request);
        $student = $portal->student($user);
        if (! $modules->isActive('fees') || ! app()->bound(FeesStanding::class)) {
            throw new NotFoundHttpException;
        }
        $doc = app(FeesStanding::class)->ownStatement($user, $student['learner_id']);

        return new Response($doc['pdf'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $doc['name']).'"',
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** The student's registration slip, once registered. */
    public function slip(Request $request, StudentPortal $portal, RegistrationSlip $slip): Response
    {
        $user = self::user($request);
        $doc = $slip->render($user, $portal->own($user));

        return new Response($doc['pdf'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $doc['name']).'"',
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
