<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Application;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Classroom\Application\Actions\CourseActions;
use Acadly\Modules\Classroom\Application\Actions\TaskActions;
use Acadly\Modules\Classroom\Contracts\ClassroomDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the console shows of Classroom (D-104): the person's courses, one
 * course page, every hand-in of one task, one hand-in for marking, and the
 * course's continuous assessment.
 *
 * @phpstan-import-type Course from Courses
 */
final class ClassroomViews
{
    public function __construct(
        private readonly Courses $courses,
        private readonly Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function courses(User $who, string $divisionId): array
    {
        $this->courses->enter($who, $divisionId);
        $period = $this->courses->current($divisionId);
        if ($period === null) {
            return ['period' => null, 'courses' => []];
        }
        $sees = $this->courses->sees($who, $divisionId);
        $out = [];
        foreach ($this->courses->offerings($divisionId, $period['id']) as $o) {
            $course = ['offering' => $o, 'period' => $period, 'division_id' => $divisionId];
            $teaches = $this->courses->teaches($who, $course);
            if (! $teaches && ! $sees) {
                continue;
            }
            $tasks = DB::table('classroom_tasks')->where('offering_id', $o['id'])->whereNull('removed_at')->get();
            $toMark = 0;
            foreach ($tasks as $t) {
                $toMark += $this->counts($t)['to_mark'];
            }
            $out[] = ['id' => $o['id'], 'code' => $o['code'], 'title' => $o['title'], 'group' => $o['group'], 'department' => $o['department'],
                'lecturers' => array_column($o['lecturers'], 'name'), 'students' => $o['taken'], 'tasks' => $tasks->count(), 'to_mark' => $toMark, 'teaches' => $teaches];
        }
        usort($out, fn (array $a, array $b): int => [! $a['teaches'], $a['code']] <=> [! $b['teaches'], $b['code']]);

        return ['period' => ['id' => $period['id'], 'name' => $period['name']], 'courses' => $out];
    }

    /** @return array<string, mixed> */
    public function course(User $who, string $divisionId, string $offeringId): array
    {
        $course = $this->courses->open($who, $divisionId, $offeringId);
        $o = $course['offering'];
        $resources = [];
        $docs = $this->documentNames(array_values(DB::table('classroom_resources')->where('offering_id', $offeringId)->whereNull('removed_at')->pluck('document_id')->filter()->all()));
        foreach (DB::table('classroom_resources')->where('offering_id', $offeringId)->whereNull('removed_at')->orderByDesc('pinned')->orderByDesc('created_at')->get() as $r) {
            $doc = $docs[Row::maybeText($r, 'document_id') ?? ''] ?? null;
            $resources[] = ['id' => Row::text($r, 'id'), 'section' => Row::text($r, 'section'), 'title' => Row::text($r, 'title'), 'kind' => Row::text($r, 'kind'), 'url' => Row::maybeText($r, 'url'),
                'document_id' => Row::maybeText($r, 'document_id'), 'name' => $doc['name'] ?? null, 'size' => $doc['size'] ?? null, 'mime' => $doc['mime'] ?? null,
                'shows_from' => ($r->shows_from ?? null) !== null ? substr(Row::text($r, 'shows_from'), 0, 10) : null, 'pinned' => (bool) $r->pinned,
                'at' => CarbonImmutable::parse(Row::text($r, 'created_at'))->toIso8601String()];
        }
        $announcements = [];
        foreach (DB::table('classroom_announcements as a')->join('users as u', 'u.id', '=', 'a.by_user_id')->where('a.offering_id', $offeringId)->orderByDesc('a.created_at')
            ->get(['a.id', 'a.title', 'a.body', 'a.sms', 'a.created_at', 'u.name']) as $a) {
            $announcements[] = ['id' => Row::text($a, 'id'), 'title' => Row::text($a, 'title'), 'body' => Row::text($a, 'body'), 'sms' => (bool) $a->sms, 'by' => Row::text($a, 'name'),
                'at' => CarbonImmutable::parse(Row::text($a, 'created_at'))->toIso8601String(), 'read' => DB::table('classroom_announcement_reads')->where('announcement_id', Row::text($a, 'id'))->count()];
        }
        $tasks = [];
        foreach (DB::table('classroom_tasks')->where('offering_id', $offeringId)->whereNull('removed_at')->orderBy('due_at')->get() as $t) {
            $tasks[] = [...$this->taskRow($t), 'counts' => $this->counts($t)];
        }
        $share = CourseActions::share($offeringId);

        return ['course' => ['id' => $o['id'], 'code' => $o['code'], 'title' => $o['title'], 'group' => $o['group'], 'department' => $o['department'], 'period' => $course['period']['name'],
            'lecturers' => array_column($o['lecturers'], 'name'), 'students' => $o['taken']], 'teaches' => $this->courses->teaches($who, $course),
            'share' => $share, 'weights' => round(array_sum(array_column($tasks, 'weight')), 2), 'resources' => $resources, 'announcements' => $announcements, 'tasks' => $tasks,
            'file_types' => array_keys(TaskActions::FILE_TYPES), 'max_files' => TaskActions::MAX_FILES, 'max_mb' => ClassroomDocument::MAX_MB];
    }

    /** @return array<string, mixed> */
    public function task(User $who, string $divisionId, string $taskId): array
    {
        [$task, $course] = $this->openTask($who, $divisionId, $taskId);
        $now = $this->clock->now();
        $students = $this->courses->students(Row::text($task, 'offering_id'));
        $events = Submissions::events($taskId, array_column($students, 'learner_id'));
        $marks = Submissions::marks($taskId);
        $files = Submissions::files('classroom_hand_in', $this->eventIds($events));
        $rows = [];
        foreach ($students as $s) {
            $st = Submissions::standing($task, $events[$s['learner_id']] ?? [], $now);
            $shown = $st['final'] ?? $st['current'];
            $at = null;
            foreach ($st['history'] as $e) {
                if ($e['id'] === $shown) {
                    $at = $e['at'];
                }
            }
            $last = $st['history'] === [] ? null : $st['history'][count($st['history']) - 1];
            $m = $marks[$s['learner_id']] ?? null;
            $rows[] = ['learner_id' => $s['learner_id'], 'name' => $s['name'], 'number' => $s['number'], 'state' => $st['state'], 'at' => $at,
                'withdrawn_at' => $last !== null && $last['action'] === 'withdraw' ? $last['at'] : null,
                'times' => count(array_filter($st['history'], fn (array $e): bool => $e['action'] === 'hand_in')), 'files' => $shown === null ? [] : ($files[$shown] ?? []),
                'mark' => $m === null ? null : ['mark' => $m['mark'], 'by' => $m['by'], 'at' => $m['at']]];
        }

        return ['task' => [...$this->taskRow($task), 'instructions' => Row::text($task, 'instructions'), 'files' => Submissions::files('classroom_task', [$taskId])[$taskId] ?? []],
            'course' => ['id' => $course['offering']['id'], 'code' => $course['offering']['code'], 'title' => $course['offering']['title']],
            'teaches' => $this->courses->teaches($who, $course), 'over' => Submissions::over($task, $now), 'counts' => $this->counts($task), 'rows' => $rows];
    }

    /**
     * One student's work on a task, for marking.
     *
     * @return array<string, mixed>
     */
    public function work(User $who, string $divisionId, string $taskId, string $learnerId): array
    {
        [$task, $course] = $this->openTask($who, $divisionId, $taskId);
        $students = $this->courses->students(Row::text($task, 'offering_id'));
        $index = array_search($learnerId, array_column($students, 'learner_id'), true);
        if ($index === false) {
            throw new NotFoundHttpException;
        }
        $now = $this->clock->now();
        $events = Submissions::events($taskId, array_column($students, 'learner_id'));
        $st = Submissions::standing($task, $events[$learnerId] ?? [], $now);
        $shown = $st['final'] ?? $st['current'];
        $mark = Submissions::marks($taskId)[$learnerId] ?? null;
        // The next and previous students with work to mark, in the list's order.
        $markable = [];
        foreach ($students as $s) {
            if (Submissions::standing($task, $events[$s['learner_id']] ?? [], $now)['final'] !== null) {
                $markable[] = $s['learner_id'];
            }
        }
        $at = array_search($learnerId, $markable, true);
        $s = $students[$index];

        return ['task' => [...$this->taskRow($task)], 'course' => ['id' => $course['offering']['id'], 'code' => $course['offering']['code'], 'title' => $course['offering']['title']],
            'teaches' => $this->courses->teaches($who, $course), 'student' => ['learner_id' => $s['learner_id'], 'name' => $s['name'], 'number' => $s['number']],
            'state' => $st['state'], 'history' => array_map(fn (array $e): array => ['action' => $e['action'], 'late' => $e['late'], 'at' => $e['at']], $st['history']),
            'files' => $shown === null ? [] : (Submissions::files('classroom_hand_in', [$shown])[$shown] ?? []),
            'mark' => $mark === null ? null : [...$mark, 'files' => Submissions::files('classroom_mark', [$mark['id']])[$mark['id']] ?? []],
            'position' => ['index' => $at === false ? 0 : $at + 1, 'of' => count($markable), 'marked' => count(array_intersect($markable, array_keys(Submissions::marks($taskId)))),
                'previous' => $at !== false && $at > 0 ? $markable[$at - 1] : null, 'next' => $at !== false && $at < count($markable) - 1 ? $markable[$at + 1] : null]];
    }

    /** @return array<string, mixed> */
    public function assessment(User $who, string $divisionId, string $offeringId): array
    {
        $course = $this->courses->open($who, $divisionId, $offeringId);
        $tasks = array_values(DB::table('classroom_tasks')->where('offering_id', $offeringId)->whereNull('removed_at')->orderBy('due_at')->get()->all());
        $grid = self::grid($tasks, $this->courses->students($offeringId), $this->clock->now(), null);
        $share = CourseActions::share($offeringId);
        $totals = array_column($grid, 'total');

        return ['course' => ['id' => $course['offering']['id'], 'code' => $course['offering']['code'], 'title' => $course['offering']['title']], 'share' => $share,
            'tasks' => array_map(fn (object $t): array => $this->taskRow($t), $tasks), 'rows' => $grid,
            'average' => $totals === [] ? null : round(array_sum($totals) / count($totals), 1),
            'released' => count(array_filter($tasks, fn (object $t): bool => ($t->released_at ?? null) !== null))];
    }

    /**
     * Each student's marks and weighted total. A task counts once its marks are released; work never handed in counts 0 then.
     *
     * @param  list<object>  $tasks
     * @param  list<array{learner_id: string, name: string, number: string, user_id: string|null, phone: string|null}>  $students
     * @return list<array{learner_id: string, name: string, number: string, cells: list<array{mark: float|null, state: string, released: bool}>, total: float, missing: int}>
     */
    public static function grid(array $tasks, array $students, CarbonImmutable $now, ?string $only): array
    {
        $events = [];
        $marks = [];
        foreach ($tasks as $t) {
            $events[Row::text($t, 'id')] = Submissions::events(Row::text($t, 'id'), $only === null ? null : [$only]);
            $marks[Row::text($t, 'id')] = Submissions::marks(Row::text($t, 'id'));
        }
        $out = [];
        foreach ($students as $s) {
            if ($only !== null && $s['learner_id'] !== $only) {
                continue;
            }
            $cells = [];
            $total = 0.0;
            $missing = 0;
            foreach ($tasks as $t) {
                $id = Row::text($t, 'id');
                $st = Submissions::standing($t, $events[$id][$s['learner_id']] ?? [], $now);
                $m = $marks[$id][$s['learner_id']] ?? null;
                $released = ($t->released_at ?? null) !== null;
                $cells[] = ['mark' => $m['mark'] ?? null, 'state' => $st['state'], 'released' => $released];
                if ($st['state'] === 'missing') {
                    $missing++;
                }
                if ($released) {
                    $total += (float) Row::text($t, 'weight') * ($m === null ? 0.0 : $m['mark'] / max(1, Row::int($t, 'out_of')));
                }
            }
            $out[] = ['learner_id' => $s['learner_id'], 'name' => $s['name'], 'number' => $s['number'], 'cells' => $cells, 'total' => round($total, 1), 'missing' => $missing];
        }

        return $out;
    }

    /** @return array{id: string, kind: string, title: string, shows_at: string, due_at: string, late_until: string|null, out_of: int, weight: float, released: bool, accepts: list<string>, max_files: int, max_mb: int} */
    public function taskRow(object $t): array
    {
        $accepts = json_decode(Row::text($t, 'accepts'), true);
        $keys = [];
        foreach (TaskActions::FILE_TYPES as $key => $types) {
            if (is_array($accepts) && in_array(ClassroomDocument::TYPES[$types[0]], $accepts, true)) {
                $keys[] = $key;
            }
        }

        return ['id' => Row::text($t, 'id'), 'kind' => Row::text($t, 'kind'), 'title' => Row::text($t, 'title'), 'shows_at' => CarbonImmutable::parse(Row::text($t, 'shows_at'))->toIso8601String(),
            'due_at' => CarbonImmutable::parse(Row::text($t, 'due_at'))->toIso8601String(),
            'late_until' => ($t->late_until ?? null) !== null ? CarbonImmutable::parse(Row::text($t, 'late_until'))->toIso8601String() : null,
            'out_of' => Row::int($t, 'out_of'), 'weight' => (float) Row::text($t, 'weight'), 'released' => ($t->released_at ?? null) !== null, 'accepts' => $keys,
            'max_files' => Row::int($t, 'max_files'), 'max_mb' => Row::int($t, 'max_mb')];
    }

    /** @return array{registered: int, handed_in: int, late: int, missing: int, open: int, marked: int, to_mark: int} */
    public function counts(object $task): array
    {
        $now = $this->clock->now();
        $students = $this->courses->students(Row::text($task, 'offering_id'));
        $events = Submissions::events(Row::text($task, 'id'));
        $marks = Submissions::marks(Row::text($task, 'id'));
        $c = ['registered' => count($students), 'handed_in' => 0, 'late' => 0, 'missing' => 0, 'open' => 0, 'marked' => 0, 'to_mark' => 0];
        foreach ($students as $s) {
            $st = Submissions::standing($task, $events[$s['learner_id']] ?? [], $now);
            match ($st['state']) {
                'handed_in', 'locked' => $c['handed_in']++,
                'late' => $c['late']++,
                'missing' => $c['missing']++,
                default => $c['open']++,
            };
            if ($st['final'] !== null) {
                isset($marks[$s['learner_id']]) ? $c['marked']++ : $c['to_mark']++;
            }
        }

        return $c;
    }

    /** @return array{0: object, 1: Course} */
    private function openTask(User $who, string $divisionId, string $taskId): array
    {
        $task = DB::table('classroom_tasks')->where('id', $taskId)->where('division_id', $divisionId)->whereNull('removed_at')->first() ?? throw new NotFoundHttpException;

        return [$task, $this->courses->open($who, $divisionId, Row::text($task, 'offering_id'))];
    }

    /**
     * @param  array<string, list<array{id: string, action: string, late: bool, at: string}>>  $events
     * @return list<string>
     */
    private function eventIds(array $events): array
    {
        $out = [];
        foreach ($events as $list) {
            foreach ($list as $e) {
                $out[] = $e['id'];
            }
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $ids
     * @return array<string, array{name: string, size: int, mime: string}>
     */
    private function documentNames(array $ids): array
    {
        $out = [];
        foreach (DB::table('documents as d')->join('document_files as f', 'f.id', '=', 'd.current_file_id')->whereIn('d.id', array_values(array_filter($ids, is_string(...))) ?: ['00000000-0000-0000-0000-000000000000'])
            ->get(['d.id', 'f.original_name', 'f.byte_size', 'f.mime_type']) as $f) {
            $out[Row::text($f, 'id')] = ['name' => Row::text($f, 'original_name'), 'size' => Row::int($f, 'byte_size'), 'mime' => Row::text($f, 'mime_type')];
        }

        return $out;
    }
}
