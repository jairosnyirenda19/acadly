<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Modules\Lectures\Application\Actions\CoverActions;
use Acadly\Modules\Lectures\Application\Actions\PhoneActions;
use Acadly\Modules\Lectures\Application\Actions\RoomActions;
use Acadly\Modules\Lectures\Application\Actions\SessionActions;
use Acadly\Modules\Lectures\Application\Actions\TimetableActions;
use Acadly\Modules\Lectures\Application\LectureRecord;
use Acadly\Modules\Lectures\Application\LectureViews;
use Acadly\Modules\Lectures\Application\Where;
use Acadly\Modules\Lectures\Contracts\LecturesPermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lectures in the console (D-102): rooms, the timetable, cover, the
 * lecturer's day, one lecture with its attendance, and the room screen.
 */
final class LecturesController
{
    /**
     * Rooms, the campus areas, and how long scanning stays open.
     *
     * @response array{rooms: list<array{id: string, code: string, name: string, seats: int, kind: string, retired: bool}>, areas: list<array{name: string, latitude: float, longitude: float, reach: int}>, scan_minutes: int, can_change: bool}
     */
    public function rooms(Request $request, string $division, Where $where, LectureViews $views): JsonResponse
    {
        $user = self::user($request);
        $where->division($user, $division, LecturesPermission::View, LecturesPermission::Timetable);

        return response()->json([...$views->rooms($division), 'can_change' => $where->allows($user, $division, LecturesPermission::Timetable)]);
    }

    /** @response 201 array{id: string} */
    public function saveRoom(Request $request, string $division, RoomActions $actions, Where $where): JsonResponse
    {
        $where->division(self::user($request), $division, LecturesPermission::Timetable);
        $request->validate(['id' => ['nullable', 'uuid'], 'code' => ['required', 'string', 'max:16'], 'name' => ['required', 'string', 'max:120'],
            'seats' => ['required', 'integer'], 'kind' => ['required', 'string', 'max:12'], 'retired' => ['boolean']]);
        $id = $actions->saveRoom(self::user($request), $division, ['id' => self::text($request, 'id'), 'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(), 'seats' => $request->integer('seats'), 'kind' => $request->string('kind')->toString(), 'retired' => $request->boolean('retired')]);

        return response()->json(['id' => $id], 201);
    }

    /** @response array{saved: bool} */
    public function saveAreas(Request $request, string $division, RoomActions $actions, Where $where): JsonResponse
    {
        $where->division(self::user($request), $division, LecturesPermission::Timetable);
        $request->validate(['areas' => ['present', 'array', 'max:10'], 'areas.*.name' => ['required', 'string', 'max:80'], 'areas.*.latitude' => ['required', 'numeric'],
            'areas.*.longitude' => ['required', 'numeric'], 'areas.*.reach' => ['required', 'integer'], 'scan_minutes' => ['required', 'integer']]);
        $areas = [];
        foreach ((array) $request->input('areas', []) as $a) {
            $a = is_array($a) ? $a : [];
            $areas[] = ['name' => is_string($a['name'] ?? null) ? $a['name'] : '', 'latitude' => is_numeric($a['latitude'] ?? null) ? (float) $a['latitude'] : 0.0,
                'longitude' => is_numeric($a['longitude'] ?? null) ? (float) $a['longitude'] : 0.0, 'reach' => is_numeric($a['reach'] ?? null) ? (int) $a['reach'] : 0];
        }
        $actions->saveAreas(self::user($request), $division, $areas, $request->integer('scan_minutes'));

        return response()->json(['saved' => true]);
    }

    /**
     * The lecture timetable of a semester: the current one unless another is asked for.
     *
     * @response array{periods: list<array{id: string, name: string}>, period: array{id: string, name: string, starts_on: string, ends_on: string}|null, published: bool, can_change: bool, rooms: list<array{id: string, code: string, name: string, seats: int, kind: string, retired: bool}>, offerings: list<array{id: string, code: string, title: string, group: string, department: string, lecturers: list<array{id: string, name: string}>, taken: int}>, cohorts: list<array{id: string, label: string, offerings: list<string>}>, staff: list<array{id: string, name: string}>, slots: list<array{id: string, offering_id: string, kind: string, weekday: int, starts: string, ends: string, room_id: string, lecturer_id: string, lecturer: string}>, checks: list<array{kind: string, tone: string, slots: list<string>, count: int}>}
     */
    public function timetable(Request $request, string $division, Where $where, LectureViews $views): JsonResponse
    {
        $user = self::user($request);
        $where->division($user, $division, LecturesPermission::View, LecturesPermission::Timetable, LecturesPermission::Teach, LecturesPermission::Cover);
        $request->validate(['period' => ['nullable', 'uuid']]);

        return response()->json($views->timetable($user, $division, self::text($request, 'period')));
    }

    /** @response 201 array{id: string} */
    public function saveSlot(Request $request, string $division, string $period, TimetableActions $actions, Where $where): JsonResponse
    {
        $where->division(self::user($request), $division, LecturesPermission::Timetable);
        $request->validate(['id' => ['nullable', 'uuid'], 'offering_id' => ['required', 'uuid'], 'kind' => ['required', 'string', 'max:10'], 'weekday' => ['required', 'integer'],
            'starts' => ['required', 'string', 'size:5'], 'ends' => ['required', 'string', 'size:5'], 'room_id' => ['required', 'uuid'], 'lecturer_id' => ['required', 'uuid']]);
        $id = $actions->save(self::user($request), $division, $period, ['id' => self::text($request, 'id'), 'offering_id' => $request->string('offering_id')->toString(),
            'kind' => $request->string('kind')->toString(), 'weekday' => $request->integer('weekday'), 'starts' => $request->string('starts')->toString(),
            'ends' => $request->string('ends')->toString(), 'room_id' => $request->string('room_id')->toString(), 'lecturer_id' => $request->string('lecturer_id')->toString()]);

        return response()->json(['id' => $id], 201);
    }

    /** @response array{saved: bool} */
    public function removeSlot(Request $request, string $division, string $id, TimetableActions $actions): JsonResponse
    {
        $actions->remove(self::user($request), $division, $id);

        return response()->json(['saved' => true]);
    }

    /**
     * Publishes the timetable, unless a check stops it.
     *
     * @response array{published: bool, checks: list<array{kind: string, tone: string, slots: list<string>, count: int}>}
     */
    public function publish(Request $request, string $division, string $period, TimetableActions $actions): JsonResponse
    {
        $stopped = $actions->publish(self::user($request), $division, $period);

        return response()->json(['published' => $stopped === [], 'checks' => $stopped]);
    }

    /**
     * The week's lectures that need cover or changed.
     *
     * @response array{week: string, can_arrange: bool, rows: list<array{slot_id: string, day: string, held_on: string, starts: string, ends: string, room: string, seats: int, kind: string, code: string, title: string, department: string, students: int, state: string, lecturer_id: string, lecturer: string, cover: string|null, asked_id: string|null, asked: string|null, reason: string|null, moved: array{day: string, starts: string, ends: string, room: string}|null, taken: array{present: int, closed: bool, scanning: bool}|null}>, counts: array{need: int, asked: int, covered: int, changed: int}}
     */
    public function cover(Request $request, string $division, Where $where, LectureViews $views): JsonResponse
    {
        $user = self::user($request);
        $where->division($user, $division, LecturesPermission::View, LecturesPermission::Cover);
        $request->validate(['week' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($views->cover($user, $division, self::text($request, 'week')));
    }

    /**
     * Colleagues who could cover a lecture, free first.
     *
     * @response array{colleagues: list<array{id: string, name: string, free: bool}>}
     */
    public function free(Request $request, string $division, string $id, Where $where, LectureViews $views): JsonResponse
    {
        $where->division(self::user($request), $division, LecturesPermission::Cover, LecturesPermission::Teach);
        $request->validate(['day' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['colleagues' => $views->free($division, $id, $request->string('day')->toString())]);
    }

    /** @response array{saved: bool} */
    public function act(Request $request, string $division, string $id, string $action, CoverActions $cover, Where $where): JsonResponse
    {
        $where->division(self::user($request), $division, LecturesPermission::Cover, LecturesPermission::Teach);
        $request->validate(['day' => ['required', 'date_format:Y-m-d'], 'user_id' => ['nullable', 'uuid'], 'reason' => ['nullable', 'string', 'max:255'],
            'to_day' => ['nullable', 'date_format:Y-m-d'], 'to_starts' => ['nullable', 'string', 'size:5'], 'to_ends' => ['nullable', 'string', 'size:5'], 'to_room' => ['nullable', 'uuid']]);
        $user = self::user($request);
        $day = $request->string('day')->toString();
        $reason = $request->string('reason')->toString();
        $colleague = self::text($request, 'user_id') ?? '';
        match ($action) {
            'away' => $cover->away($user, $division, $id, $day, $reason),
            'ask' => $cover->ask($user, $division, $id, $day, $colleague),
            'accept' => $cover->answer($user, $division, $id, $day, true),
            'decline' => $cover->answer($user, $division, $id, $day, false),
            'name' => $cover->name($user, $division, $id, $day, $colleague),
            'cancel' => $cover->cancel($user, $division, $id, $day, $reason),
            default => $cover->move($user, $division, $id, $day, ['day' => self::text($request, 'to_day') ?? '', 'starts' => self::text($request, 'to_starts') ?? '',
                'ends' => self::text($request, 'to_ends') ?? '', 'room_id' => self::text($request, 'to_room')], $reason),
        };

        return response()->json(['saved' => true]);
    }

    /**
     * The person's lectures on a day, the cover asked of them and their week ahead.
     *
     * @response array{day: string, now: string, today: list<array{slot_id: string, day: string, held_on: string, starts: string, ends: string, room: string, seats: int, kind: string, code: string, title: string, department: string, students: int, state: string, lecturer_id: string, lecturer: string, cover: string|null, asked_id: string|null, asked: string|null, reason: string|null, moved: array{day: string, starts: string, ends: string, room: string}|null, taken: array{present: int, closed: bool, scanning: bool}|null}>, asked: list<array{slot_id: string, day: string, held_on: string, starts: string, ends: string, room: string, seats: int, kind: string, code: string, title: string, department: string, students: int, state: string, lecturer_id: string, lecturer: string, cover: string|null, asked_id: string|null, asked: string|null, reason: string|null, moved: array{day: string, starts: string, ends: string, room: string}|null, taken: array{present: int, closed: bool, scanning: bool}|null}>, ahead: list<array{slot_id: string, day: string, held_on: string, starts: string, ends: string, room: string, seats: int, kind: string, code: string, title: string, department: string, students: int, state: string, lecturer_id: string, lecturer: string, cover: string|null, asked_id: string|null, asked: string|null, reason: string|null, moved: array{day: string, starts: string, ends: string, room: string}|null, taken: array{present: int, closed: bool, scanning: bool}|null}>}
     */
    public function myDay(Request $request, string $division, Where $where, LectureViews $views): JsonResponse
    {
        $user = self::user($request);
        $where->division($user, $division, LecturesPermission::Teach);
        $request->validate(['day' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($views->myDay($user, $division, self::text($request, 'day')));
    }

    /**
     * One lecture on one day, with its attendance.
     *
     * @response array{lecture: array{slot_id: string, day: string, held_on: string, starts: string, ends: string, room: string, seats: int, kind: string, code: string, title: string, department: string, students: int, state: string, lecturer_id: string, lecturer: string, cover: string|null, asked_id: string|null, asked: string|null, reason: string|null, moved: array{day: string, starts: string, ends: string, room: string}|null, taken: array{present: int, closed: bool, scanning: bool}|null}, session: array{id: string, scan_until: string, scanning: bool, closed: bool}|null, students: list<array{learner_id: string, name: string, number: string, mark: string|null, source: string|null, at: string|null, reason: string|null, refused: array{why: string, at: string}|null}>, can_open: bool}
     */
    public function lecture(Request $request, string $division, string $id, Where $where, LectureViews $views): JsonResponse
    {
        $where->division(self::user($request), $division, LecturesPermission::Teach, LecturesPermission::Cover, LecturesPermission::View);
        $request->validate(['day' => ['required', 'date_format:Y-m-d']]);

        return response()->json($views->lecture($division, $id, $request->string('day')->toString()));
    }

    /** @response array{session: string} */
    public function open(Request $request, string $division, string $id, SessionActions $sessions, Where $where): JsonResponse
    {
        $where->division(self::user($request), $division, LecturesPermission::Teach, LecturesPermission::Cover);
        $request->validate(['day' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['session' => $sessions->open(self::user($request), $division, $id, $request->string('day')->toString())]);
    }

    /** @response array{saved: bool} */
    public function close(Request $request, string $division, string $id, SessionActions $sessions): JsonResponse
    {
        $sessions->close(self::user($request), $division, $id);

        return response()->json(['saved' => true]);
    }

    /** @response array{saved: bool} */
    public function mark(Request $request, string $division, string $id, SessionActions $sessions, Where $where): JsonResponse
    {
        $where->division(self::user($request), $division, LecturesPermission::Teach, LecturesPermission::Cover);
        $request->validate(['learner_id' => ['required', 'uuid'], 'mark' => ['required', 'string', 'max:8'], 'reason' => ['required', 'string', 'max:255']]);
        $sessions->mark(self::user($request), $division, $id, $request->string('learner_id')->toString(), $request->string('mark')->toString(), $request->string('reason')->toString());

        return response()->json(['saved' => true]);
    }

    /**
     * The room screen: the code now, and how many have scanned in.
     *
     * @response array{code: string, changes_in: int, scanned: int, of: int, scan_until: string, open: bool, lecture: array{slot_id: string, day: string, held_on: string, starts: string, ends: string, room: string, seats: int, kind: string, code: string, title: string, department: string, students: int, state: string, lecturer_id: string, lecturer: string, cover: string|null, asked_id: string|null, asked: string|null, reason: string|null, moved: array{day: string, starts: string, ends: string, room: string}|null, taken: array{present: int, closed: bool, scanning: bool}|null}}
     */
    public function code(Request $request, string $division, string $id, Where $where, LectureViews $views): JsonResponse
    {
        $where->division(self::user($request), $division, LecturesPermission::Teach, LecturesPermission::Cover);

        return response()->json($views->code($division, $id))->header('Cache-Control', 'no-store');
    }

    /**
     * The Lecture attendance tab of the learner record.
     *
     * @response array{semester: string|null, rate: int|null, held: int, missed: int, bar: int|null, courses: list<array{code: string, title: string, held: int, missed: int, late: int, rate: int|null}>, marks: list<array{day: string, code: string, kind: string, mark: string, source: string, reason: string|null, by: string|null, was: string|null}>, phone: array{set: bool, set_on: string|null}, can_reset: bool}
     */
    public function learner(Request $request, string $learner, LectureRecord $record): JsonResponse
    {
        return response()->json($record->tab(self::user($request), $learner));
    }

    /** @response array{saved: bool} */
    public function resetPhone(Request $request, string $learner, PhoneActions $phones): JsonResponse
    {
        $phones->mayReset(self::user($request), $learner);
        $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $phones->reset(self::user($request), $learner, $request->string('reason')->toString());

        return response()->json(['saved' => true]);
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
