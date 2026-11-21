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
use Acadly\Modules\Lectures\Application\LectureDays;
use Acadly\Modules\Lectures\Application\Where;
use Acadly\Modules\Lectures\Contracts\LecturesAudit;
use Acadly\Modules\Lectures\Contracts\LecturesPermission;
use Acadly\Modules\Lectures\Notifications\CoverAsked;
use Acadly\Modules\Lectures\Notifications\LectureChanged;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Cover (D-102). A lecturer who cannot come says so, with a reason, and may
 * ask a colleague; the colleague accepts or declines. The head of department
 * names a colleague, or cancels or moves the lecture, with a reason. Each
 * step is a new row. The students of the course are told when the lecture is
 * covered, cancelled or moved.
 *
 * @phpstan-import-type Lecture from LectureDays
 */
final class CoverActions
{
    public function __construct(
        private readonly Where $where,
        private readonly LectureDays $days,
        private readonly CourseTeaching $teaching,
        private readonly Audit $audit,
        private readonly Notifier $notifier,
        private readonly Clock $clock,
    ) {}

    /** The lecturer cannot come. Their own lecture, or anyone's for those who arrange cover. */
    public function away(User $by, string $divisionId, string $slotId, string $day, string $reason): void
    {
        $l = $this->lecture($by, $divisionId, $slotId, $day, true);
        if (! in_array($l['state'], ['on', 'covered'], true)) {
            throw ValidationException::withMessages(['day' => ['This lecture already needs cover, or is cancelled or moved.']]);
        }
        $reason = self::reason($reason);
        $this->write($by, $l, 'away', null, $reason, LecturesAudit::Away);
    }

    /** Asks a free colleague to cover. */
    public function ask(User $by, string $divisionId, string $slotId, string $day, string $userId): void
    {
        $l = $this->lecture($by, $divisionId, $slotId, $day, true);
        if ($l['state'] !== 'away') {
            throw ValidationException::withMessages(['user_id' => ['Say first that the lecturer cannot come.']]);
        }
        $this->colleague($l, $userId);
        $id = $this->write($by, $l, 'ask', $userId, null, LecturesAudit::CoverAsked);
        $this->notifier->send(new CoverAsked($id, $userId, $divisionId, $this->course($l).', '.$l['room'], $this->when($l), false), $by);
    }

    /** The colleague asked answers. */
    public function answer(User $by, string $divisionId, string $slotId, string $day, bool $accept): void
    {
        $this->where->division($by, $divisionId, LecturesPermission::Teach);
        $l = $this->days->one($slotId, $day);
        if ($l === null || $l['division_id'] !== $divisionId || $l['state'] !== 'asked' || $l['asked_id'] !== $by->id) {
            throw new NotFoundHttpException;
        }
        $id = $this->write($by, $l, $accept ? 'accept' : 'decline', $by->id, null, LecturesAudit::CoverAnswered);
        if ($accept) {
            $this->tell($by, $id, $l, 'is covered by '.$by->name);
        }
    }

    /** The head of department names a colleague: the cover is set at once. */
    public function name(User $by, string $divisionId, string $slotId, string $day, string $userId): void
    {
        $l = $this->lecture($by, $divisionId, $slotId, $day, false);
        if ($l['state'] === 'cancelled') {
            throw ValidationException::withMessages(['user_id' => ['This lecture is cancelled.']]);
        }
        $user = $this->colleague($l, $userId);
        $id = $this->write($by, $l, 'cover', $userId, null, LecturesAudit::CoverNamed);
        $this->notifier->send(new CoverAsked($id, $userId, $divisionId, $this->course($l).', '.$l['room'], $this->when($l), true), $by);
        $this->tell($by, $id, $l, 'is covered by '.$user);
    }

    public function cancel(User $by, string $divisionId, string $slotId, string $day, string $reason): void
    {
        $l = $this->lecture($by, $divisionId, $slotId, $day, false);
        $this->notTaken($l);
        if ($l['state'] === 'cancelled') {
            throw ValidationException::withMessages(['reason' => ['This lecture is cancelled already.']]);
        }
        $id = $this->write($by, $l, 'cancel', null, self::reason($reason), LecturesAudit::Cancelled);
        $this->tell($by, $id, $l, 'is cancelled');
    }

    /** @param  array{day: string, starts: string, ends: string, room_id: string|null}  $to */
    public function move(User $by, string $divisionId, string $slotId, string $day, array $to, string $reason): void
    {
        $l = $this->lecture($by, $divisionId, $slotId, $day, false);
        $this->notTaken($l);
        if ($l['state'] === 'cancelled') {
            throw ValidationException::withMessages(['reason' => ['This lecture is cancelled.']]);
        }
        $errors = [];
        $period = $this->days->period($divisionId, $to['day']);
        if ($period === null || $period['id'] !== $l['period_id'] || $to['day'] < $this->clock->today()) {
            $errors['to_day'] = ['Choose a day in this semester, today or later.'];
        }
        if (! TimetableActions::time($to['starts']) || ! TimetableActions::time($to['ends']) || $to['starts'] >= $to['ends']) {
            $errors['to_ends'] = ['Give a start and an end time, the end after the start.'];
        }
        $room = $to['room_id'] ?? $l['room_id'];
        $code = DB::table('lecture_rooms')->where('id', $room)->where('division_id', $divisionId)->whereNull('retired_at')->value('code');
        if (! is_string($code)) {
            $errors['to_room'] = ['Choose a room.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $reason = self::reason($reason);
        $id = Ids::next();

        DB::transaction(function () use ($by, $l, $id, $to, $room, $reason): void {
            DB::table('lecture_changes')->insert(['id' => $id, 'division_id' => $l['division_id'], 'slot_id' => $l['slot_id'], 'day' => $l['day'], 'kind' => 'move', 'user_id' => null,
                'reason' => $reason, 'moved_day' => $to['day'], 'moved_starts' => $to['starts'], 'moved_ends' => $to['ends'], 'moved_room_id' => $room, 'by_user_id' => $by->id,
                'created_at' => $this->clock->now()]);
            $this->audit->record(LecturesAudit::Moved, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $l['division_id']), reason: $reason,
                meta: ['slot' => $l['slot_id'], 'day' => $l['day'], 'to' => $to['day']]);
        });
        $this->tell($by, $id, $l, 'is moved to '.CarbonImmutable::parse($to['day'])->format('D j M').', '.$to['starts'].', '.(is_string($code) ? $code : ''));
    }

    /**
     * The lecture, after checking who acts: the lecturer of record (to say they cannot come and to ask), or one who arranges cover.
     *
     * @return Lecture
     */
    private function lecture(User $by, string $divisionId, string $slotId, string $day, bool $lecturerMay): array
    {
        $this->where->division($by, $divisionId, ...($lecturerMay ? [LecturesPermission::Cover, LecturesPermission::Teach] : [LecturesPermission::Cover]));
        $l = $this->days->one($slotId, $day);
        if ($l === null || $l['division_id'] !== $divisionId || ! LectureDays::published($l['period_id'])) {
            throw new NotFoundHttpException;
        }
        if (! $this->where->allows($by, $divisionId, LecturesPermission::Cover) && $l['lecturer_id'] !== $by->id) {
            throw new AccessDeniedHttpException;
        }
        if ($l['day'] < $this->clock->today()) {
            throw ValidationException::withMessages(['day' => ['This lecture has passed.']]);
        }

        return $l;
    }

    /** @param  Lecture  $l */
    private function notTaken(array $l): void
    {
        if (DB::table('lecture_sessions')->where('slot_id', $l['slot_id'])->where('day', $l['day'])->exists()) {
            throw ValidationException::withMessages(['reason' => ['Attendance is taken at this lecture already.']]);
        }
    }

    /**
     * Checks a colleague is active and free at the time, and gives their name.
     *
     * @param  Lecture  $l
     */
    private function colleague(array $l, string $userId): string
    {
        $user = DB::table('users')->where('id', $userId)->where('status', 'active')->value('name');
        if (! is_string($user) || $userId === $l['lecturer_id']) {
            throw ValidationException::withMessages(['user_id' => ['Choose a colleague.']]);
        }
        foreach ($this->days->on([$l['division_id']], $l['held_on']) as $other) {
            if ($other['slot_id'] !== $l['slot_id'] && $other['teacher_id'] === $userId && LectureDays::given($other, $l['held_on'])
                && $other['starts'] < $l['ends'] && $l['starts'] < $other['ends']) {
                throw ValidationException::withMessages(['user_id' => ['This colleague gives another lecture at that time.']]);
            }
        }

        return $user;
    }

    /** @param  Lecture  $l */
    private function write(User $by, array $l, string $kind, ?string $userId, ?string $reason, LecturesAudit $audit): string
    {
        $id = Ids::next();
        DB::transaction(function () use ($by, $l, $kind, $userId, $reason, $audit, $id): void {
            DB::table('lecture_changes')->insert(['id' => $id, 'division_id' => $l['division_id'], 'slot_id' => $l['slot_id'], 'day' => $l['day'], 'kind' => $kind, 'user_id' => $userId,
                'reason' => $reason, 'by_user_id' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record($audit, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $l['division_id']), reason: $reason,
                meta: ['slot' => $l['slot_id'], 'day' => $l['day'], 'kind' => $kind]);
        });

        return $id;
    }

    /** @param  Lecture  $l */
    private function tell(User $by, string $changeId, array $l, string $what): void
    {
        $this->notifier->send(new LectureChanged($changeId, $l['offering_id'], $l['division_id'], $this->course($l), $this->when($l), $what), $by);
    }

    /** @param  Lecture  $l */
    private function course(array $l): string
    {
        foreach ($this->teaching->offerings($l['division_id'], $l['period_id']) as $o) {
            if ($o['id'] === $l['offering_id']) {
                return $o['code'].' '.ucfirst($l['kind']);
            }
        }

        return ucfirst($l['kind']);
    }

    /** @param  Lecture  $l */
    private function when(array $l): string
    {
        return CarbonImmutable::parse($l['day'])->format('D j M').', '.$l['starts'].' to '.$l['ends'];
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);

        return $reason !== '' ? mb_substr($reason, 0, 255) : throw ValidationException::withMessages(['reason' => ['Give the reason.']]);
    }
}
