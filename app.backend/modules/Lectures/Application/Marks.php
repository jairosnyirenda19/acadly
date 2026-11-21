<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application;

use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reading lecture marks (D-102): the latest mark of each student counts;
 * the earlier ones are the history. A student's rate is the share of closed
 * lectures they attended: present or late.
 *
 * @phpstan-type Rate array{offering_id: string, held: int, attended: int, late: int, missed: int}
 * @phpstan-type Missed array{session_id: string, offering_id: string, slot_id: string, held_on: string, mark: string, source: string, reason: string|null, by: string|null, was: string|null}
 */
final class Marks
{
    /**
     * The latest mark of each student at the sessions, by "session|learner".
     *
     * @param  list<string>  $sessionIds
     * @return array<string, object>
     */
    public static function latest(array $sessionIds, ?string $learnerId = null): array
    {
        $out = [];
        if ($sessionIds === []) {
            return $out;
        }
        $q = DB::table('lecture_marks as m')->leftJoin('users as u', 'u.id', '=', 'm.by_user_id')->whereIn('m.session_id', $sessionIds);
        if ($learnerId !== null) {
            $q->where('m.learner_id', $learnerId);
        }
        foreach ($q->orderBy('m.created_at')->orderBy('m.id')->get(['m.*', 'u.name as by_name']) as $m) {
            $key = Row::text($m, 'session_id').'|'.Row::text($m, 'learner_id');
            $m->was = isset($out[$key]) ? Row::text($out[$key], 'mark') : null;
            $out[$key] = $m;
        }

        return $out;
    }

    /**
     * A student's attendance by course, at the closed lectures of a semester.
     *
     * @return array{rates: list<Rate>, missed: list<Missed>}
     */
    public static function ofLearner(string $learnerId, string $periodId): array
    {
        $sessions = DB::table('lecture_sessions as s')->join('lecture_slots as sl', 'sl.id', '=', 's.slot_id')->where('sl.period_id', $periodId)->whereNotNull('s.closed_at')
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('lecture_marks as m')->whereColumn('m.session_id', 's.id')->where('m.learner_id', $learnerId))
            ->orderByDesc('s.held_on')->get(['s.id', 's.offering_id', 's.slot_id', 's.held_on'])->all();
        $marks = self::latest(array_values(array_map(fn (object $s): string => Row::text($s, 'id'), $sessions)), $learnerId);
        $rates = [];
        $missed = [];
        foreach ($sessions as $s) {
            $m = $marks[Row::text($s, 'id').'|'.$learnerId] ?? null;
            if ($m === null) {
                continue;
            }
            $o = Row::text($s, 'offering_id');
            $rates[$o] ??= ['offering_id' => $o, 'held' => 0, 'attended' => 0, 'late' => 0, 'missed' => 0];
            $rates[$o]['held']++;
            $mark = Row::text($m, 'mark');
            $rates[$o][$mark === 'absent' ? 'missed' : 'attended']++;
            if ($mark === 'late') {
                $rates[$o]['late']++;
            }
            if ($mark !== 'present' || Row::text($m, 'source') === 'hand') {
                $missed[] = ['session_id' => Row::text($s, 'id'), 'offering_id' => $o, 'slot_id' => Row::text($s, 'slot_id'), 'held_on' => substr(Row::text($s, 'held_on'), 0, 10),
                    'mark' => $mark, 'source' => Row::text($m, 'source'), 'reason' => Row::maybeText($m, 'reason'), 'by' => Row::maybeText($m, 'by_name'),
                    'was' => is_string($m->was ?? null) ? $m->was : null];
            }
        }

        return ['rates' => array_values($rates), 'missed' => $missed];
    }

    /** A whole share in per cent, or null with nothing held. */
    public static function percent(int $part, int $whole): ?int
    {
        return $whole === 0 ? null : (int) round(100 * $part / $whole);
    }

    public static function day(string $day): string
    {
        return CarbonImmutable::parse($day)->format('D j M');
    }
}
