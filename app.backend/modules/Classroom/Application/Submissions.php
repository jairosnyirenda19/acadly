<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Application;

use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Where a student's work on one assignment or exam paper stands (D-104),
 * read from the hand-in and withdrawal rows. Before the deadline the student
 * may withdraw and hand in again. At the deadline the hand-in in force is
 * locked and goes to marking. After it, until the late day, a student with
 * nothing locked may hand in once, marked Late, and it locks at once. An
 * exam paper takes nothing late.
 *
 * @phpstan-type Event array{id: string, action: string, late: bool, at: string}
 * @phpstan-type Standing array{state: 'not_open'|'open'|'handed_in'|'locked'|'late'|'missing'|'late_open', final: string|null, current: string|null, can_hand_in: bool, can_withdraw: bool, history: list<Event>}
 */
final class Submissions
{
    /**
     * The rows of each student for a task, oldest first, by learner.
     *
     * @param  list<string>|null  $learners
     * @return array<string, list<Event>>
     */
    public static function events(string $taskId, ?array $learners = null): array
    {
        $q = DB::table('classroom_hand_ins')->where('task_id', $taskId);
        if ($learners !== null) {
            $q->whereIn('learner_id', $learners ?: ['00000000-0000-0000-0000-000000000000']);
        }
        $out = [];
        foreach ($q->orderBy('created_at')->orderBy('id')->get(['id', 'learner_id', 'action', 'late', 'created_at']) as $e) {
            $out[Row::text($e, 'learner_id')][] = ['id' => Row::text($e, 'id'), 'action' => Row::text($e, 'action'), 'late' => (bool) $e->late,
                'at' => CarbonImmutable::parse(Row::text($e, 'created_at'))->toIso8601String()];
        }

        return $out;
    }

    /**
     * @param  object  $task  a classroom_tasks row
     * @param  list<Event>  $events
     * @return Standing
     */
    public static function standing(object $task, array $events, CarbonImmutable $now): array
    {
        $shows = CarbonImmutable::parse(Row::text($task, 'shows_at'));
        $due = CarbonImmutable::parse(Row::text($task, 'due_at'));
        $lateUntil = ($task->late_until ?? null) !== null && Row::text($task, 'kind') !== 'exam' ? CarbonImmutable::parse(Row::text($task, 'late_until')) : null;

        // What stood at the deadline: the last on-time row, if it was a hand-in.
        $current = null;
        foreach ($events as $e) {
            if (! $e['late']) {
                $current = $e['action'] === 'hand_in' ? $e['id'] : null;
            }
        }
        $late = null;
        foreach ($events as $e) {
            if ($e['late'] && $e['action'] === 'hand_in') {
                $late = $e['id'];
                break;
            }
        }
        $before = $now->lessThanOrEqualTo($due);
        if ($now->lessThan($shows)) {
            return ['state' => 'not_open', 'final' => null, 'current' => null, 'can_hand_in' => false, 'can_withdraw' => false, 'history' => $events];
        }
        if ($before) {
            return ['state' => $current !== null ? 'handed_in' : 'open', 'final' => null, 'current' => $current, 'can_hand_in' => $current === null,
                'can_withdraw' => $current !== null, 'history' => $events];
        }
        if ($current !== null) {
            return ['state' => 'locked', 'final' => $current, 'current' => $current, 'can_hand_in' => false, 'can_withdraw' => false, 'history' => $events];
        }
        if ($late !== null) {
            return ['state' => 'late', 'final' => $late, 'current' => $late, 'can_hand_in' => false, 'can_withdraw' => false, 'history' => $events];
        }
        $lateOpen = $lateUntil !== null && $now->lessThanOrEqualTo($lateUntil);

        return ['state' => $lateOpen ? 'late_open' : 'missing', 'final' => null, 'current' => null, 'can_hand_in' => $lateOpen, 'can_withdraw' => false, 'history' => $events];
    }

    /** Whether the time for hand-ins is over: the late day, or the deadline for an exam paper or a task with no late day. */
    public static function over(object $task, CarbonImmutable $now): bool
    {
        $end = ($task->late_until ?? null) !== null && Row::text($task, 'kind') !== 'exam' ? Row::text($task, 'late_until') : Row::text($task, 'due_at');

        return $now->greaterThan(CarbonImmutable::parse($end));
    }

    /**
     * The latest mark of each student for a task, by learner.
     *
     * @return array<string, array{id: string, mark: float, feedback: string|null, reason: string|null, at: string, by: string, was: float|null}>
     */
    public static function marks(string $taskId): array
    {
        $out = [];
        foreach (DB::table('classroom_marks as m')->join('users as u', 'u.id', '=', 'm.by_user_id')->where('m.task_id', $taskId)->orderBy('m.created_at')->orderBy('m.id')
            ->get(['m.id', 'm.learner_id', 'm.mark', 'm.feedback', 'm.reason', 'm.created_at', 'u.name']) as $m) {
            $l = Row::text($m, 'learner_id');
            $out[$l] = ['id' => Row::text($m, 'id'), 'mark' => (float) Row::text($m, 'mark'), 'feedback' => Row::maybeText($m, 'feedback'), 'reason' => Row::maybeText($m, 'reason'),
                'at' => CarbonImmutable::parse(Row::text($m, 'created_at'))->toIso8601String(), 'by' => Row::text($m, 'name'), 'was' => isset($out[$l]) ? $out[$l]['mark'] : null];
        }

        return $out;
    }

    /**
     * The documents a hand-in or a mark holds, by owner.
     *
     * @param  list<string>  $ownerIds
     * @return array<string, list<array{id: string, name: string, size: int, mime: string}>>
     */
    public static function files(string $ownerType, array $ownerIds): array
    {
        $out = [];
        if ($ownerIds === []) {
            return $out;
        }
        foreach (DB::table('documents as d')->join('document_files as f', 'f.id', '=', 'd.current_file_id')->where('d.owner_type', $ownerType)->whereIn('d.owner_id', $ownerIds)
            ->whereNull('d.trashed_at')->whereNull('d.purged_at')->orderBy('d.created_at')->get(['d.id', 'd.owner_id', 'f.original_name', 'f.byte_size', 'f.mime_type']) as $f) {
            $out[Row::text($f, 'owner_id')][] = ['id' => Row::text($f, 'id'), 'name' => Row::text($f, 'original_name'), 'size' => Row::int($f, 'byte_size'), 'mime' => Row::text($f, 'mime_type')];
        }

        return $out;
    }
}
