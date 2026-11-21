<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application\Actions;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Lectures\Application\LectureDays;
use Acadly\Modules\Lectures\Application\TimetableChecks;
use Acadly\Modules\Lectures\Application\Where;
use Acadly\Modules\Lectures\Contracts\LecturesAudit;
use Acadly\Modules\Lectures\Contracts\LecturesPermission;
use Acadly\Modules\Lectures\Notifications\LectureChanged;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Building and publishing a semester's lecture timetable (D-102). A clash
 * stops the publish. Once published, a change tells the students of the
 * course it changes.
 *
 * @phpstan-type SlotInput array{id: string|null, offering_id: string, kind: string, weekday: int, starts: string, ends: string, room_id: string, lecturer_id: string}
 */
final class TimetableActions
{
    public const array DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];

    public function __construct(
        private readonly Where $where,
        private readonly LectureDays $days,
        private readonly CourseTeaching $teaching,
        private readonly TimetableChecks $checks,
        private readonly Audit $audit,
        private readonly Notifier $notifier,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  SlotInput  $input
     * @return string the slot's id
     */
    public function save(User $by, string $divisionId, string $periodId, array $input): string
    {
        $this->where->division($by, $divisionId, LecturesPermission::Timetable);
        $this->period($divisionId, $periodId);
        $offerings = [];
        foreach ($this->teaching->offerings($divisionId, $periodId) as $o) {
            $offerings[$o['id']] = $o;
        }
        $errors = [];
        if (! isset($offerings[$input['offering_id']])) {
            $errors['offering_id'] = ['Choose a course offered this semester.'];
        }
        if (! in_array($input['kind'], LectureDays::KINDS, true)) {
            $errors['kind'] = ['Choose lecture, tutorial or practical.'];
        }
        if (! isset(self::DAYS[$input['weekday']])) {
            $errors['weekday'] = ['Choose a day from Monday to Saturday.'];
        }
        if (! self::time($input['starts']) || ! self::time($input['ends']) || $input['starts'] >= $input['ends']) {
            $errors['ends'] = ['Give a start and an end time, the end after the start.'];
        }
        if (! DB::table('lecture_rooms')->where('id', $input['room_id'])->where('division_id', $divisionId)->whereNull('retired_at')->exists()) {
            $errors['room_id'] = ['Choose a room.'];
        }
        if (! DB::table('users')->where('id', $input['lecturer_id'])->where('status', 'active')->exists()) {
            $errors['lecturer_id'] = ['Choose the lecturer.'];
        }
        $old = null;
        if ($input['id'] !== null) {
            $old = Where::owned('lecture_slots', $input['id'], $divisionId);
            if (Row::text($old, 'period_id') !== $periodId || ($old->retired_at ?? null) !== null) {
                throw new NotFoundHttpException;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $id = $input['id'] ?? Ids::next();
        $now = $this->clock->now();
        $row = ['offering_id' => $input['offering_id'], 'kind' => $input['kind'], 'weekday' => $input['weekday'], 'starts' => $input['starts'], 'ends' => $input['ends'],
            'room_id' => $input['room_id'], 'lecturer_id' => $input['lecturer_id'], 'updated_at' => $now];
        $published = LectureDays::published($periodId);

        DB::transaction(function () use ($by, $divisionId, $periodId, $input, $id, $row, $now, $old, $published, $offerings): void {
            if ($old === null) {
                DB::table('lecture_slots')->insert([...$row, 'id' => $id, 'division_id' => $divisionId, 'period_id' => $periodId, 'created_at' => $now]);
            } else {
                DB::table('lecture_slots')->where('id', $id)->update($row);
            }
            $this->audit->record(LecturesAudit::SlotSaved, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId), meta: ['slot' => $id]);
            if ($published) {
                $o = $offerings[$input['offering_id']];
                $this->notifier->send(new LectureChanged(Ids::next(), $input['offering_id'], $divisionId, $o['code'].' '.ucfirst($input['kind']),
                    self::DAYS[$input['weekday']].', '.$input['starts'].' to '.$input['ends'], $old === null ? 'is added to the timetable' : 'has a new time or room on the timetable'), $by);
            }
        });

        return $id;
    }

    public function remove(User $by, string $divisionId, string $slotId): void
    {
        $this->where->division($by, $divisionId, LecturesPermission::Timetable);
        $slot = Where::owned('lecture_slots', $slotId, $divisionId);
        if (($slot->retired_at ?? null) !== null) {
            throw new NotFoundHttpException;
        }
        $published = LectureDays::published(Row::text($slot, 'period_id'));
        $code = '';
        foreach ($this->teaching->offerings($divisionId, Row::text($slot, 'period_id')) as $o) {
            if ($o['id'] === Row::text($slot, 'offering_id')) {
                $code = $o['code'];
            }
        }

        DB::transaction(function () use ($by, $divisionId, $slotId, $slot, $published, $code): void {
            DB::table('lecture_slots')->where('id', $slotId)->update(['retired_at' => $this->clock->now()]);
            $this->audit->record(LecturesAudit::SlotRemoved, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId), meta: ['slot' => $slotId]);
            if ($published) {
                $this->notifier->send(new LectureChanged(Ids::next(), Row::text($slot, 'offering_id'), $divisionId, $code.' '.ucfirst(Row::text($slot, 'kind')),
                    self::DAYS[Row::int($slot, 'weekday')] ?? '', 'is taken off the timetable'), $by);
            }
        });
    }

    /**
     * Publishes the semester's timetable, unless a check stops it.
     *
     * @return list<array{kind: string, tone: string, slots: list<string>, count: int}> the checks that stopped it; none when published
     */
    public function publish(User $by, string $divisionId, string $periodId): array
    {
        $this->where->division($by, $divisionId, LecturesPermission::Timetable);
        $this->period($divisionId, $periodId);
        $checks = $this->checks->run($divisionId, $periodId);
        if (TimetableChecks::blocks($checks)) {
            return array_values(array_filter($checks, fn (array $c): bool => $c['tone'] === 'wrong'));
        }
        if (LectureDays::published($periodId)) {
            return [];
        }

        DB::transaction(function () use ($by, $divisionId, $periodId): void {
            DB::table('lecture_timetables')->insert(['period_id' => $periodId, 'division_id' => $divisionId, 'published_at' => $this->clock->now(), 'published_by' => $by->id]);
            $this->audit->record(LecturesAudit::Published, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId), meta: ['period' => $periodId]);
        });

        return [];
    }

    /** @return array{id: string, name: string, starts_on: string, ends_on: string} */
    private function period(string $divisionId, string $periodId): array
    {
        foreach ($this->days->periods($divisionId, $this->clock->today()) as $p) {
            if ($p['id'] === $periodId) {
                return $p;
            }
        }
        throw new NotFoundHttpException;
    }

    public static function time(string $t): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1;
    }
}
