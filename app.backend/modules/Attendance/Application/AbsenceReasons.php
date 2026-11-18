<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Actions\SubmitRegister;
use Acadly\Modules\Attendance\Contracts\Mark;
use Acadly\Modules\Attendance\Journeys\Correction\State;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The reasons families gave for morning absences (D-084). Staff and the
 * family see the latest reason of each absence; every reason is kept.
 */
final class AbsenceReasons
{
    public const string SICK = 'sick';

    public const string FAMILY = 'family';

    public const string TRAVEL = 'travel';

    public const string OTHER = 'other';

    public const array KINDS = [self::SICK, self::FAMILY, self::TRAVEL, self::OTHER];

    /** The words staff read in a correction's reason. */
    public const array LABELS = [self::SICK => 'Sick or seeing a doctor', self::FAMILY => 'Family matter', self::TRAVEL => 'Could not travel to school', self::OTHER => 'Another reason'];

    public function __construct(private readonly EnrolmentDirectory $learners) {}

    /**
     * The morning absences in these classes that a family explained and
     * nobody has accepted yet, newest first (D-086): the mark in force is
     * still absent, no correction is waiting, and the day is within the
     * days a family may still give a reason.
     *
     * @param  list<string>  $classIds
     * @return list<array{register_id: string, class_id: string, day: string, learner_id: string, learner: string, kind: string}>
     */
    public function toAccept(array $classIds, string $today): array
    {
        if ($classIds === []) {
            return [];
        }

        $from = CarbonImmutable::parse($today)->subDays(self::OPEN_DAYS)->toDateString();
        $rows = DB::table('attendance_absence_reasons as a')->join('attendance_registers as r', 'r.id', '=', 'a.register_id')
            ->whereIn('r.class_id', $classIds)->where('r.kind', SubmitRegister::KIND)->where('r.day', '>=', $from)->where('r.day', '<=', $today)
            ->orderBy('a.given_at')->orderBy('a.id')->get(['a.register_id', 'a.learner_id', 'a.kind', 'r.class_id', 'r.day']);
        $latest = [];

        foreach ($rows as $r) {
            $latest[Row::text($r, 'register_id').':'.Row::text($r, 'learner_id')] = $r;
        }

        $out = [];

        foreach ($latest as $r) {
            $register = Row::text($r, 'register_id');
            $learner = Row::text($r, 'learner_id');
            $mark = DB::table('attendance_marks')->where('register_id', $register)->where('learner_id', $learner)->orderByDesc('received_at')->orderByDesc('id')->value('status');
            $waiting = DB::table('attendance_corrections')->where('register_id', $register)->where('learner_id', $learner)
                ->whereIn('state', [State::Drafted->value, State::Requested->value])->exists();

            if ($mark === Mark::Absent->value && ! $waiting) {
                $out[] = ['register_id' => $register, 'class_id' => Row::text($r, 'class_id'), 'day' => substr(Row::text($r, 'day'), 0, 10), 'learner_id' => $learner, 'learner' => '', 'kind' => Row::text($r, 'kind')];
            }
        }

        $names = $this->learners->names(array_values(array_unique(array_column($out, 'learner_id'))));
        usort($out, fn (array $a, array $b): int => [$b['day'], $a['learner_id']] <=> [$a['day'], $b['learner_id']]);

        return array_map(fn (array $o): array => [...$o, 'learner' => $names[$o['learner_id']] ?? ''], $out);
    }

    /** A family may give a reason up to this many days after the absence. */
    public const int OPEN_DAYS = 14;

    /**
     * The latest reason for each learner on these registers.
     *
     * @param  list<string>  $registerIds
     * @return array<string, array{kind: string, note: string|null, by: string, by_id: string, at: string}> keyed "register:learner"
     */
    public function latest(array $registerIds, ?string $learnerId = null): array
    {
        if ($registerIds === []) {
            return [];
        }

        $rows = DB::table('attendance_absence_reasons')->whereIn('register_id', $registerIds)
            ->when($learnerId !== null, fn ($q) => $q->where('learner_id', $learnerId))
            ->orderBy('given_at')->orderBy('id')->get();
        $names = DB::table('users')->whereIn('id', array_values(array_unique(array_map(fn (object $r): string => Row::text($r, 'given_by'), $rows->all()))) ?: [Lessons::NONE])
            ->pluck('name', 'id')->all();
        $out = [];

        foreach ($rows as $r) {
            $by = Row::text($r, 'given_by');
            $out[Row::text($r, 'register_id').':'.Row::text($r, 'learner_id')] = [
                'kind' => Row::text($r, 'kind'),
                'note' => Row::maybeText($r, 'note'),
                'by' => is_string($names[$by] ?? null) ? $names[$by] : '',
                'by_id' => $by,
                'at' => Row::text($r, 'given_at'),
            ];
        }

        return $out;
    }

    /**
     * The reason as staff see it, or null.
     *
     * @param  array<string, array{kind: string, note: string|null, by: string, by_id: string, at: string}>  $latest
     * @return array{kind: string, note: string|null, by: string, at: string}|null
     */
    public static function forStaff(array $latest, string $registerId, string $learnerId): ?array
    {
        $r = $latest[$registerId.':'.$learnerId] ?? null;

        return $r === null ? null : ['kind' => $r['kind'], 'note' => $r['note'], 'by' => $r['by'], 'at' => $r['at']];
    }
}
