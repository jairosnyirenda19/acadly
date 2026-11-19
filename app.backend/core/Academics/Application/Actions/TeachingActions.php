<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\AcademicsAudit;
use Acadly\Core\Academics\Contracts\AcademicsPermission;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Who teaches what (the approved Teaching screen). Giving a subject in a
 * class, or a class as its class teacher, records who teaches it. It grants
 * nothing: what the teacher may do there still comes from their roles. Only
 * someone who holds "Teach classes" at the class can be given it. A change
 * ends the row in force and adds a new one.
 */
final class TeachingActions
{
    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** Gives a subject in a class (or the class itself, with no subject) to a teacher, or to nobody. */
    public function give(User $by, string $classId, ?string $subjectId, ?string $userId): void
    {
        $class = $this->classInForce($by, $classId);
        $at = ScopeRef::of(ScopeType::ClassGroup, $classId);

        if ($subjectId !== null) {
            $subject = DB::table('academic_subjects')->where('id', $subjectId)->whereNull('retired_at')->first(['division_id', 'levels']);
            $levels = $subject !== null ? json_decode(Row::text($subject, 'levels'), true) : null;

            if ($subject === null || Row::text($subject, 'division_id') !== Row::text($class, 'division_id')
                || ! is_array($levels) || ! in_array(Row::text($class, 'level_code'), $levels, true)) {
                throw ValidationException::withMessages(['subject_id' => ['This class does not take this subject.']]);
            }
        }

        if ($userId !== null && ! in_array($userId, $this->access->holderIds(AcademicsPermission::Teach, $at), true)) {
            throw ValidationException::withMessages(['user_id' => ['This person cannot be given teaching in this class. Their roles there do not include Teach classes.']]);
        }

        DB::transaction(function () use ($by, $classId, $subjectId, $userId, $at): void {
            $now = $this->clock->now();
            $current = DB::table('academic_teaching')->where('class_id', $classId)->whereNull('ended_at')
                ->where(fn (Builder $q): Builder => $subjectId === null ? $q->whereNull('subject_id') : $q->where('subject_id', $subjectId))
                ->lockForUpdate()->first(['id', 'user_id']);

            if ($current !== null && Row::text($current, 'user_id') === $userId) {
                return;
            }

            if ($current !== null) {
                DB::table('academic_teaching')->where('id', Row::text($current, 'id'))->update(['ended_at' => $now, 'ended_by' => $by->id]);
            }

            if ($userId !== null) {
                DB::table('academic_teaching')->insert(['id' => Ids::next(), 'class_id' => $classId, 'subject_id' => $subjectId,
                    'user_id' => $userId, 'given_by' => $by->id, 'given_at' => $now]);
            }

            $this->audit->record(AcademicsAudit::TeachingGiven, actor: $this->audit->actorFor($by), at: $at,
                meta: ['class' => $classId, 'subject' => $subjectId, 'to' => $userId, 'from' => $current !== null ? Row::text($current, 'user_id') : null]);
        });
    }

    /**
     * Copies who taught what in the year before into the current year, for one
     * level at one campus: class by class, by level and stream, into places
     * not yet given, and only to people who can still teach the class.
     *
     * @return int how many were copied
     */
    public function copy(User $by, string $divisionId, string $campusId, string $offeringId): int
    {
        $this->access->check($by, AcademicsPermission::Manage, ScopeRef::of(ScopeType::Offering, $offeringId));
        $year = $this->calendar->currentYear();
        $before = $year === null ? null : DB::table('academic_years')->where('ends_on', '<', $year['starts_on'])->orderByDesc('ends_on')->first(['id']);

        if ($year === null || $before === null) {
            throw ValidationException::withMessages(['year' => ['There is no earlier year to copy from.']]);
        }

        $key = fn (object $c): string => Row::text($c, 'level_code').'|'.(Row::maybeText($c, 'stream') ?? '');
        $classes = fn (string $yearId) => DB::table('academic_classes')->where('year_id', $yearId)->where('division_id', $divisionId)
            ->where('campus_id', $campusId)->get(['id', 'level_code', 'stream', 'ended_at']);
        $now = [];

        foreach ($classes($year['id'])->whereNull('ended_at') as $class) {
            $now[$key($class)] = Row::text($class, 'id');
        }

        return DB::transaction(function () use ($by, $classes, $before, $key, $now): int {
            $copied = 0;

            foreach ($classes(Row::text($before, 'id')) as $old) {
                $target = $now[$key($old)] ?? null;

                if ($target === null) {
                    continue;
                }

                $teachers = $this->access->holderIds(AcademicsPermission::Teach, ScopeRef::of(ScopeType::ClassGroup, $target));
                $given = DB::table('academic_teaching')->where('class_id', $target)->whereNull('ended_at')->get(['subject_id'])
                    ->map(fn (object $r): string => Row::maybeText($r, 'subject_id') ?? '')->all();

                foreach (DB::table('academic_teaching')->where('class_id', Row::text($old, 'id'))->whereNull('ended_at')->get(['subject_id', 'user_id']) as $row) {
                    $subject = Row::maybeText($row, 'subject_id');

                    if (in_array($subject ?? '', $given, true) || ! in_array(Row::text($row, 'user_id'), $teachers, true)) {
                        continue;
                    }

                    DB::table('academic_teaching')->insert(['id' => Ids::next(), 'class_id' => $target, 'subject_id' => $subject,
                        'user_id' => Row::text($row, 'user_id'), 'given_by' => $by->id, 'given_at' => $this->clock->now()]);
                    $copied++;
                }
            }

            $this->audit->record(AcademicsAudit::TeachingCopied, actor: $this->audit->actorFor($by), meta: ['copied' => $copied]);

            return $copied;
        });
    }

    /** The class, when the person may manage it; refused before anything else. */
    private function classInForce(User $by, string $classId): object
    {
        try {
            $at = ScopeRef::of(ScopeType::ClassGroup, $classId);
        } catch (InvalidArgumentException) {
            // Not an id: refused, else invalid, before any lookup.
            $this->access->check($by, AcademicsPermission::Manage, ScopeRef::institution());
            throw ValidationException::withMessages(['class_id' => ['Choose a class.']]);
        }

        $this->access->check($by, AcademicsPermission::Manage, $at);
        $class = DB::table('academic_classes')->where('id', $classId)->whereNull('ended_at')->first(['division_id', 'campus_id', 'level_code']);

        return $class ?? throw ValidationException::withMessages(['class_id' => ['The class does not exist or has ended.']]);
    }
}
