<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Http;

use Acadly\Core\Academics\Contracts\Timetable;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Attendance\Application\Actions\CoverActions;
use Acadly\Modules\Attendance\Application\Actions\SubmitLessonRegister;
use Acadly\Modules\Attendance\Application\LearnerAttendance;
use Acadly\Modules\Attendance\Application\Lessons;
use Acadly\Modules\Attendance\Application\Reach;
use Acadly\Modules\Attendance\Application\Registers;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The lesson screens (the approved designs): My day, Lessons, Missed lessons, the lesson register, away and cover. */
final class LessonsController
{
    public function __construct(
        private readonly Lessons $lessons,
        private readonly Registers $registers,
        private readonly Clock $clock,
    ) {}

    /**
     * How each level takes registers.
     *
     * @response array{levels: list<array{division: string, mode: 'daily'|'daily_and_lessons'}>}
     */
    public function levels(Request $request): JsonResponse
    {
        return response()->json(['levels' => $this->lessons->levels(self::user($request))]);
    }

    /**
     * The viewer's day: `?day=YYYY-MM-DD`.
     *
     * @response array{day: string, today: string, now: string, away: array{reason: string|null}|null, morning: list<array{class_id: string, class: string, school_day: bool, submitted_at: string|null}>, reasons: list<array{register_id: string, class_id: string, day: string, learner_id: string, learner: string, kind: 'sick'|'family'|'travel'|'other'}>, bells: list<array{number: int|null, name: string, starts: string, ends: string, kind: 'lesson'|'break'|'activity', register: bool}>, lessons: list<array{slot_id: string, period: int, name: string, starts: string, ends: string, class_id: string, class: string, subject: string, subject_short: string, kind: 'lesson'|'activity', whole_level: bool, required: bool, state: 'taken'|'cover'|'no_register'|'not_needed'|'needs_cover'|'now'|'later', register: array{id: string, present: int, total: int}|null, mine: bool, cover_for: string|null, asked_by: string|null}>}
     */
    public function myDay(Request $request): JsonResponse
    {
        return response()->json($this->lessons->myDay(self::user($request), $this->day($request)));
    }

    /**
     * Every lesson of the day in the classes the viewer may see: `?day=YYYY-MM-DD`.
     *
     * @response array{day: string, today: string, now: string, places: list<array{label: string, bells: list<array{number: int|null, name: string, starts: string, ends: string, kind: 'lesson'|'break'|'activity', register: bool}>, classes: list<array{id: string, name: string, lessons: list<array{slot_id: string, period: int, name: string, starts: string, ends: string, class_id: string, class: string, subject: string, subject_short: string, kind: 'lesson'|'activity', whole_level: bool, required: bool, state: 'taken'|'cover'|'no_register'|'not_needed'|'needs_cover'|'now'|'later', register: array{id: string, present: int, total: int}|null, teacher: string|null, taken_by: string|null, asked: string|null}>}>}>, away: list<array{user_id: string, name: string, reason: string|null, marked_by: string|null, marked_at: string, lessons: list<array{slot_id: string, period: int, name: string, starts: string, ends: string, class_id: string, class: string, subject: string, subject_short: string, kind: 'lesson'|'activity', whole_level: bool, required: bool, state: 'taken'|'cover'|'no_register'|'not_needed'|'needs_cover'|'now'|'later', register: array{id: string, present: int, total: int}|null, asked: string|null, can_arrange: bool}>}>, totals: array{lessons: int, so_far: int, taken: int, cover: int, no_register: int, needs_cover: int}, can_arrange: bool}
     */
    public function today(Request $request): JsonResponse
    {
        return response()->json($this->lessons->today(self::user($request), $this->day($request)));
    }

    /**
     * Learners here this morning and absent from a lesson: `?day=YYYY-MM-DD`.
     *
     * @response array{day: string, today: string, notice_on: bool, missed: list<array{register_id: string, learner_id: string, learner: string, class: string, morning: 'present'|'late', subject: string, period: int, starts: string, taken_by: string, cover: bool, told: list<array{to: string, channel: string, state: 'delivered'|'sent'|'accepted'|'not_delivered'|'waiting'|'not_reachable', at: string}>, follow_ups: list<array{by: string, at: string, note: string|null}>, can_follow_up: bool}>, totals: array{missed: int, learners: int, followed_up: int, told: int}}
     */
    public function missed(Request $request): JsonResponse
    {
        return response()->json($this->lessons->missed(self::user($request), $this->day($request)));
    }

    /**
     * One lesson's register: `?day=YYYY-MM-DD`.
     *
     * @response array{class: array{id: string, name: string}, lesson: array{slot_id: string, period: int, name: string, starts: string, ends: string, kind: 'lesson'|'activity', whole_level: bool, required: bool, subject: string, teacher: string|null}, previous: array{kind: 'lesson'|'morning', name: string|null, by: string, at: string}|null, day: string, today: string, school_day: bool, morning_taken: bool, earlier: array{period: int}|null, register: array{id: string, submitted_at: string, submitted_by: string, cover: bool}|null, cover: bool, learners: list<array{id: string, name: string, admission_number: string|null, morning: string|null, earlier: string|null, before: string|null, mark: array{status: string, note: string|null, corrected: bool}|null, correction: array{id: string, from: string, to: string, reason: string, state: string, requested_by: string, requested_at: string, can_decide: bool, waiting_for: string|null}|null}>, can_take: bool, can_request: bool}
     */
    public function show(Request $request, string $slot): JsonResponse
    {
        return response()->json($this->lessons->lesson(self::user($request), $slot, $this->day($request), $this->registers));
    }

    /**
     * Attendance's part of a learner's record: the term, every morning, lessons by subject, absences and corrections.
     *
     * @response array{term: array{name: string, starts_on: string, ends_on: string}|null, today: string, totals: array{marked: int, present: int, absent: int, late: int, missed: int, missed_today: int, share: int|null, last_absent: string|null}, weeks: list<array{week: string, days: list<array{day: string, mark: 'present'|'absent'|'late'|'excused'|null, school_day: bool, future: bool}>}>, takes_lessons: bool, lessons_today: list<array{period: int, name: string, starts: string, subject: string, kind: 'lesson'|'activity', mark: 'present'|'absent'|'late'|'excused'|null}>, subjects: list<array{subject: string, present: int, total: int}>, absences: list<array{day: string, what: 'absent'|'missed'|'excused', lesson: string|null, register_id: string, reason: string|null, from_family: array{kind: 'sick'|'family'|'travel'|'other', note: string|null, by: string, at: string}|null, told: list<array{to: string, channel: string, state: 'delivered'|'sent'|'accepted'|'not_delivered'|'waiting'|'not_reachable', at: string}>, follow_ups: list<array{kind: 'told_next'|'called', by: string, at: string, note: string|null}>}>, corrections: list<array{day: string, register: string, from: string, to: string, reason: string, asked_by: string, state: string, at: string}>}
     */
    public function learner(Request $request, string $id, LearnerAttendance $attendance): JsonResponse
    {
        return response()->json($attendance->record(self::user($request), $id));
    }

    /** Submits a lesson register: `{day, marks: [{learner_id, status, note}]}`. */
    public function submit(Request $request, string $slot, SubmitLessonRegister $submit): JsonResponse
    {
        $viewer = self::user($request);
        $day = $this->day($request);
        $submit->handle($viewer, $slot, $day, RegistersController::marksOf($request->input('marks')));

        return response()->json($this->lessons->lesson($viewer, $slot, $day, $this->registers));
    }

    /** Marks someone away on a day, or back: `{user_id?, day, away, reason?}`. Without `user_id`, the viewer. */
    public function away(Request $request, CoverActions $cover): JsonResponse
    {
        $viewer = self::user($request);
        $reason = $request->input('reason');
        $day = $this->day($request);
        $cover->away($viewer, $request->filled('user_id') ? $request->string('user_id')->toString() : $viewer->id, $day, $request->boolean('away'), is_string($reason) ? $reason : null);

        return response()->json(['ok' => true]);
    }

    /**
     * Who is free to cover a lesson: `?slot_id=&day=`.
     *
     * @response array{free: list<array{id: string, name: string, lessons: int}>}
     */
    public function free(Request $request, Timetable $timetable, Access $access, Reach $reach): JsonResponse
    {
        $viewer = self::user($request);
        $lesson = $timetable->lesson($request->string('slot_id')->toString(), $this->day($request));

        if ($lesson === null || ! $reach->sees($viewer, $lesson['class_id'])) {
            $access->check($viewer, AttendancePermission::Cover);

            throw new NotFoundHttpException;
        }

        $access->check($viewer, AttendancePermission::Cover, ScopeRef::of(ScopeType::ClassGroup, $lesson['class_id']));

        return response()->json(['free' => $this->lessons->free($lesson)]);
    }

    /** Asks someone to cover a lesson: `{slot_id, day, user_id}`. */
    public function ask(Request $request, CoverActions $cover): JsonResponse
    {
        $viewer = self::user($request);
        $day = $this->day($request);
        $cover->ask($viewer, $request->string('slot_id')->toString(), $day, $request->string('user_id')->toString());

        return response()->json($this->lessons->today($viewer, $day));
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

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
