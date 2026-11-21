<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Modules\Lectures\Application\Actions\PhoneActions;
use Acadly\Modules\Lectures\Application\Actions\SessionActions;
use Acadly\Modules\Lectures\Application\StudentLectures;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lectures in the student portal (D-102).
 */
final class StudentLecturesController
{
    public function __construct(private readonly CourseTeaching $teaching) {}

    /**
     * Today's lectures, the one open for scanning now, and the attendance phone.
     *
     * @response array{day: string, lectures: list<array{slot_id: string, day: string, held_on: string, starts: string, ends: string, room: string, kind: string, code: string, title: string, state: string, lecturer: string, cover: string|null, moved: array{day: string, starts: string, ends: string, room: string}|null, mark: string|null, scan: array{open: bool, until: string}|null}>, open: array{slot_id: string, day: string, held_on: string, starts: string, ends: string, room: string, kind: string, code: string, title: string, state: string, lecturer: string, cover: string|null, moved: array{day: string, starts: string, ends: string, room: string}|null, mark: string|null, scan: array{open: bool, until: string}|null}|null, phone: array{set: bool, credential_id: string|null, set_on: string|null, rp_id: string}, location: bool}
     */
    public function today(Request $request, StudentLectures $lectures): JsonResponse
    {
        return response()->json($lectures->today($this->user($request)));
    }

    /**
     * A week of lectures, Monday to Saturday.
     *
     * @response array{from: string, days: list<array{day: string, lectures: list<array{slot_id: string, day: string, held_on: string, starts: string, ends: string, room: string, kind: string, code: string, title: string, state: string, lecturer: string, cover: string|null, moved: array{day: string, starts: string, ends: string, room: string}|null, mark: string|null, scan: array{open: bool, until: string}|null}>}>}
     */
    public function week(Request $request, StudentLectures $lectures): JsonResponse
    {
        $student = $this->user($request);
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d']]);
        $from = $request->input('from');

        return response()->json($lectures->week($student, is_string($from) ? $from : null));
    }

    /**
     * The student's lecture attendance this semester.
     *
     * @response array{semester: string|null, rate: int|null, held: int, missed: int, courses: list<array{code: string, title: string, held: int, missed: int, late: int, rate: int|null}>, marks: list<array{day: string, code: string, kind: string, mark: string, source: string, reason: string|null, by: string|null, was: string|null}>}
     */
    public function attendance(Request $request, StudentLectures $lectures): JsonResponse
    {
        return response()->json($lectures->attendance($this->user($request)));
    }

    /**
     * Starts setting up the attendance phone: what the phone needs to make its passkey.
     *
     * @response array{challenge: string, rp_id: string, user_handle: string, name: string}
     */
    public function phoneBegin(Request $request, PhoneActions $phones): JsonResponse
    {
        return response()->json($phones->begin($this->user($request)));
    }

    /** @response array{saved: bool} */
    public function phoneSet(Request $request, PhoneActions $phones): JsonResponse
    {
        $student = $this->user($request);
        $request->validate(['client_data_json' => ['required', 'string', 'max:4096'], 'attestation_object' => ['required', 'string', 'max:16384']]);
        $phones->set($student, $request->string('client_data_json')->toString(), $request->string('attestation_object')->toString());

        return response()->json(['saved' => true]);
    }

    /**
     * Scans in at a lecture.
     *
     * @response array{result: string, why: string|null, course: string, at: string|null}
     */
    public function scan(Request $request, SessionActions $sessions): JsonResponse
    {
        $student = $this->user($request);
        $request->validate(['code' => ['required', 'string', 'max:120'], 'credential_id' => ['required', 'string', 'max:512'], 'client_data_json' => ['required', 'string', 'max:4096'],
            'authenticator_data' => ['required', 'string', 'max:2048'], 'signature' => ['required', 'string', 'max:512'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'], 'accuracy' => ['nullable', 'numeric', 'min:0']]);
        $location = $request->filled('latitude') && $request->filled('longitude')
            ? ['latitude' => $request->float('latitude'), 'longitude' => $request->float('longitude'), 'accuracy' => $request->float('accuracy')] : null;

        return response()->json($sessions->scan($student, $request->string('code')->toString(), ['credential_id' => $request->string('credential_id')->toString(),
            'client_data_json' => $request->string('client_data_json')->toString(), 'authenticator_data' => $request->string('authenticator_data')->toString(),
            'signature' => $request->string('signature')->toString()], $location));
    }

    /** The signed-in student; anyone else finds nothing here. */
    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $this->teaching->studentOf($user->id) !== null ? $user : throw new NotFoundHttpException;
    }
}
