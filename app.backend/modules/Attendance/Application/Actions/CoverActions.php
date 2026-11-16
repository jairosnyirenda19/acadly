<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application\Actions;

use Acadly\Core\Academics\Contracts\Timetable;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\Application\Lessons;
use Acadly\Modules\Attendance\Contracts\AttendanceAudit;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Staff away and cover (the approved designs). Both are optional: anyone who
 * may take a class's registers can take a lesson register, and it is recorded
 * as cover when they are not the timetabled teacher. A teacher may say they
 * are away; someone who arranges cover may say so for them, and ask someone
 * free to cover a lesson. Each is a row added; the latest is in force.
 */
final class CoverActions
{
    public function __construct(
        private readonly Access $access,
        private readonly Timetable $timetable,
        private readonly Lessons $lessons,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** Marks someone away on a day, or back. Oneself: needs to take registers where one teaches. Someone else: needs to arrange cover there. */
    public function away(User $by, string $userId, string $day, bool $away, ?string $reason): void
    {
        $userId = Str::isUuid($userId) ? $userId : $by->id;
        $self = $userId === $by->id;
        $classes = array_values(array_unique(array_column($this->timetable->lessonsOf($userId, $day), 'class_id')));
        $permission = $self ? AttendancePermission::Capture : AttendancePermission::Cover;
        $allowed = array_filter($classes, fn (string $c): bool => $this->access->allows($by, $permission, ScopeRef::of(ScopeType::ClassGroup, $c)));

        if ($allowed === []) {
            $this->access->check($by, $permission);
        }

        if (! DB::table('users')->where('id', $userId)->exists()) {
            throw ValidationException::withMessages(['user_id' => ['Choose a member of staff.']]);
        }

        if ($day < $this->clock->today()) {
            throw ValidationException::withMessages(['day' => ['Choose today or a day still to come.']]);
        }

        $reason = trim((string) $reason);

        if (mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => ['A reason can be up to 255 characters.']]);
        }

        DB::transaction(function () use ($by, $userId, $day, $away, $reason): void {
            DB::table('attendance_away')->insert(['id' => Ids::next(), 'user_id' => $userId, 'day' => $day, 'away' => $away,
                'reason' => $reason !== '' ? $reason : null, 'marked_by' => $by->id, 'marked_at' => $this->clock->now()]);
            $this->audit->record(AttendanceAudit::AwaySet, actor: $this->audit->actorFor($by), meta: ['user' => $userId, 'day' => $day, 'away' => $away ? 1 : 0]);
        });
    }

    /** Asks someone to cover a lesson. They must be able to take the class's registers, be in, and be free then. */
    public function ask(User $by, string $slotId, string $day, string $userId): void
    {
        $lesson = $this->timetable->lesson($slotId, $day);

        if ($lesson === null) {
            $this->access->check($by, AttendancePermission::Cover);

            throw new NotFoundHttpException;
        }

        $this->access->check($by, AttendancePermission::Cover, ScopeRef::of(ScopeType::ClassGroup, $lesson['class_id']));

        if ($day < $this->clock->today()) {
            throw ValidationException::withMessages(['day' => ['Choose today or a day still to come.']]);
        }

        if (! in_array($userId, array_column($this->lessons->free($lesson), 'id'), true)) {
            throw ValidationException::withMessages(['user_id' => ['This person cannot cover this lesson: they are away, teaching then, or cannot take the class\'s registers.']]);
        }

        DB::transaction(function () use ($by, $lesson, $day, $userId): void {
            DB::table('attendance_cover_asks')->insert(['id' => Ids::next(), 'slot_id' => $lesson['slot_id'], 'day' => $day, 'user_id' => $userId,
                'asked_by' => $by->id, 'asked_at' => $this->clock->now()]);
            $this->audit->record(AttendanceAudit::CoverAsked, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::ClassGroup, $lesson['class_id']),
                meta: ['user' => $userId, 'day' => $day, 'period' => $lesson['period']]);
        });
    }
}
