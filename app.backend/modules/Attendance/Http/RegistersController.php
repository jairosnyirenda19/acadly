<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Http;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Access\WorkPlaces;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Actions\AbsenceFollowUp;
use Acadly\Modules\Attendance\Application\Actions\AcceptAbsenceReason;
use Acadly\Modules\Attendance\Application\Actions\CorrectionActions;
use Acadly\Modules\Attendance\Application\Actions\SubmitLessonRegister;
use Acadly\Modules\Attendance\Application\Actions\SubmitRegister;
use Acadly\Modules\Attendance\Application\Lessons;
use Acadly\Modules\Attendance\Application\Registers;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The Registers screens (the approved designs). */
final class RegistersController
{
    public function __construct(
        private readonly Registers $registers,
        private readonly Lessons $lessons,
        private readonly Clock $clock,
    ) {}

    /**
     * Every register the viewer may see on a day: `?day=YYYY-MM-DD&in=type:id`.
     *
     * @response array{day: string, today: string, year: string|null, lessons: bool, classes: list<array{id: string, name: string, division: string, campus: string, teacher: string|null, school_day: bool, due: bool, register: array{id: string, submitted_at: string, submitted_by: string}|null, learners: int, counts: array{present: int, absent: int, late: int, excused: int}, corrections: int}>, totals: array{classes: int, due: int, taken: int, not_taken: int, present: int, absent: int, late: int, excused: int, corrections: int}}
     */
    public function index(Request $request, WorkPlaces $places): JsonResponse
    {
        $viewer = self::user($request);

        return response()->json($this->registers->day($viewer, $this->day($request), self::workPlace($request, $viewer, $places)));
    }

    /**
     * One class's register on a day: `?day=YYYY-MM-DD`.
     *
     * @response array{class: array{id: string, name: string}, day: string, today: string, school_day: bool, teacher: string|null, register: array{id: string, submitted_at: string, submitted_by: string}|null, days_before: list<string>, learners: list<array{id: string, name: string, admission_number: string|null, history: list<string|null>, mark: array{status: string, note: string|null, corrected: bool}|null, correction: array{id: string, from: string, to: string, reason: string, state: string, requested_by: string, requested_at: string, can_decide: bool, waiting_for: string|null}|null, absence: array{told: list<array{to: string, relationship: string, channel: string, state: 'delivered'|'sent'|'accepted'|'not_delivered'|'waiting'|'not_reachable', at: string}>, next: string|null, from_family: array{kind: 'sick'|'family'|'travel'|'other', note: string|null, by: string, at: string}|null, follow_ups: list<array{kind: 'told_next'|'called', by: string, at: string, note: string|null}>, can_follow_up: bool}|null}>, can_take: bool, late: bool, late_request: array{id: string, reason: string, marks: array<string, string>, asked_by: string, waiting_for: list<string>}|null, can_request: bool, approves: list<string>, approves_own: bool}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->registers->register(self::user($request), $id, $this->day($request)));
    }

    /** Submits the register: `{day, marks: [{learner_id, status, note}], reason}`. A register for an earlier day may be asked for instead, with the reason. */
    public function submit(Request $request, string $id, SubmitRegister $submit): JsonResponse
    {
        $viewer = self::user($request);
        $day = $this->day($request);
        $submit->handle($viewer, $id, $day, self::marksOf($request->input('marks')), self::text($request, 'reason'));

        return response()->json($this->registers->register($viewer, $id, $day));
    }

    /** Asks for a correction: `{learner_id, to, reason}`. */
    public function requestCorrection(Request $request, string $register, CorrectionActions $corrections): JsonResponse
    {
        $viewer = self::user($request);
        $corrections->request($viewer, $register, self::text($request, 'learner_id'), self::text($request, 'to'), self::text($request, 'reason'));

        return $this->registerOf($viewer, $register);
    }

    /** Decides on a correction: `{approve, note}`. */
    public function decide(Request $request, string $id, CorrectionActions $corrections): JsonResponse
    {
        $viewer = self::user($request);
        $correction = $corrections->decide($viewer, $id, $request->boolean('approve'), self::text($request, 'note'));

        return $this->registerOf($viewer, $correction->register_id);
    }

    private function registerOf(User $viewer, string $registerId): JsonResponse
    {
        $row = DB::table('attendance_registers')->where('id', $registerId)->first(['class_id', 'day', 'kind', 'slot_id']) ?? throw new NotFoundHttpException;

        if (Row::text($row, 'kind') === SubmitLessonRegister::KIND) {
            return response()->json($this->lessons->lesson($viewer, Row::text($row, 'slot_id'), substr(Row::text($row, 'day'), 0, 10), $this->registers));
        }

        return response()->json($this->registers->register($viewer, Row::text($row, 'class_id'), substr(Row::text($row, 'day'), 0, 10)));
    }

    private function day(Request $request): string
    {
        $day = $request->string('day')->toString();

        if ($day === '') {
            return $this->clock->today();
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw ValidationException::withMessages(['day' => ['Give a day as YYYY-MM-DD.']]);
        }

        return $day;
    }

    /** Tells the next contact of an absent learner, when the first could not be reached. */
    public function tellNext(Request $request, string $register, string $learner, AbsenceFollowUp $followUp): JsonResponse
    {
        $followUp->tellNext(self::user($request), $register, $learner);

        return $this->registerOf(self::user($request), $register);
    }

    /** Accepts the reason the family gave for an absence: asks for a correction from absent to excused (D-085). */
    public function accept(Request $request, string $register, string $learner, AcceptAbsenceReason $accept): JsonResponse
    {
        $accept->accept(self::user($request), $register, $learner);

        return $this->registerOf(self::user($request), $register);
    }

    /** Records that the family of an absent learner was called: `{note}`. */
    public function called(Request $request, string $register, string $learner, AbsenceFollowUp $followUp): JsonResponse
    {
        $note = $request->input('note');
        $followUp->called(self::user($request), $register, $learner, is_string($note) ? $note : null);

        return $this->registerOf(self::user($request), $register);
    }

    /** @return list<array{learner_id: string, status: string, note: string|null}> */
    public static function marksOf(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 500) {
            return [];
        }

        $out = [];

        foreach ($value as $m) {
            $m = is_array($m) ? $m : [];
            $out[] = [
                'learner_id' => is_string($m['learner_id'] ?? null) ? $m['learner_id'] : '',
                'status' => is_string($m['status'] ?? null) ? $m['status'] : '',
                'note' => is_string($m['note'] ?? null) ? $m['note'] : null,
            ];
        }

        return $out;
    }

    private static function workPlace(Request $request, User $viewer, WorkPlaces $places): ?ScopeRef
    {
        $in = $request->string('in')->toString();

        if ($in === '') {
            return null;
        }

        [$type, $id] = array_pad(explode(':', $in, 2), 2, '');
        $kind = ScopeType::tryFrom($type);

        try {
            $place = $kind !== null ? ScopeRef::of($kind, $id !== '' ? $id : null) : null;
        } catch (InvalidArgumentException) {
            $place = null;
        }

        if ($place === null || ! $places->allows($viewer, $place)) {
            throw new NotFoundHttpException;
        }

        return $place;
    }

    private static function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
