<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Application;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Classroom\Application\Actions\CourseActions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Classroom in the student portal (D-104): the student's courses, a course
 * page with its work, announcements, resources and marks, one task with the
 * student's own hand-in, and the work due soon. Only courses the student
 * holds a place in; only their own hand-ins; marks once released.
 *
 * @phpstan-type Me array{learner_id: string, division_id: string}
 */
final class StudentClassroom
{
    public function __construct(
        private readonly Courses $courses,
        private readonly ClassroomViews $views,
        private readonly Clock $clock,
    ) {}

    /** @return list<array{id: string, code: string, title: string, lecturers: list<string>, due: int, new: int}> */
    public function courses(User $user): array
    {
        $me = $this->me($user);
        $out = [];
        foreach ($this->held($me) as $c) {
            $o = $c['offering'];
            $due = 0;
            foreach ($this->tasks($o['id']) as $t) {
                $st = Submissions::standing($t, Submissions::events(Row::text($t, 'id'), [$me['learner_id']])[$me['learner_id']] ?? [], $this->clock->now());
                $due += $st['can_hand_in'] ? 1 : 0;
            }
            $out[] = ['id' => $o['id'], 'code' => $o['code'], 'title' => $o['title'], 'lecturers' => array_column($o['lecturers'], 'name'), 'due' => $due,
                'new' => $this->unread($user, $o['id'])];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function course(User $user, string $offeringId): array
    {
        $me = $this->me($user);
        $c = $this->heldCourse($me, $offeringId);
        $o = $c['offering'];
        $now = $this->clock->now();
        $tasks = [];
        $shown = [];
        foreach ($this->tasks($offeringId) as $t) {
            $st = Submissions::standing($t, Submissions::events(Row::text($t, 'id'), [$me['learner_id']])[$me['learner_id']] ?? [], $now);
            $m = ($t->released_at ?? null) !== null ? (Submissions::marks(Row::text($t, 'id'))[$me['learner_id']] ?? null) : null;
            $tasks[] = [...$this->views->taskRow($t), 'state' => $st['state'], 'mark' => $m['mark'] ?? null];
            if ($now->greaterThanOrEqualTo(CarbonImmutable::parse(Row::text($t, 'shows_at')))) {
                $shown[] = $t;
            }
        }
        $today = $this->clock->today();
        $resources = [];
        foreach (DB::table('classroom_resources')->where('offering_id', $offeringId)->whereNull('removed_at')->where(fn (Builder $q) => $q->whereNull('shows_from')->orWhere('shows_from', '<=', $today))
            ->orderByDesc('pinned')->orderByDesc('created_at')->get() as $r) {
            $resources[] = ['id' => Row::text($r, 'id'), 'section' => Row::text($r, 'section'), 'title' => Row::text($r, 'title'), 'kind' => Row::text($r, 'kind'), 'url' => Row::maybeText($r, 'url'),
                'document_id' => Row::maybeText($r, 'document_id'), 'pinned' => (bool) $r->pinned];
        }
        $announcements = [];
        foreach (DB::table('classroom_announcements as a')->join('users as u', 'u.id', '=', 'a.by_user_id')->where('a.offering_id', $offeringId)->orderByDesc('a.created_at')->limit(30)
            ->get(['a.id', 'a.title', 'a.body', 'a.created_at', 'u.name']) as $a) {
            $announcements[] = ['id' => Row::text($a, 'id'), 'title' => Row::text($a, 'title'), 'body' => Row::text($a, 'body'), 'by' => Row::text($a, 'name'),
                'at' => CarbonImmutable::parse(Row::text($a, 'created_at'))->toIso8601String(),
                'read' => DB::table('classroom_announcement_reads')->where('announcement_id', Row::text($a, 'id'))->where('user_id', $user->id)->exists()];
        }
        $grid = ClassroomViews::grid(array_values(array_filter($shown, fn (object $t): bool => ($t->released_at ?? null) !== null)), $this->courses->students($offeringId), $now, $me['learner_id']);

        return ['course' => ['id' => $o['id'], 'code' => $o['code'], 'title' => $o['title'], 'lecturers' => array_column($o['lecturers'], 'name')],
            'tasks' => array_values(array_filter($tasks, fn (array $t): bool => $t['state'] !== 'not_open')), 'coming' => array_values(array_filter($tasks, fn (array $t): bool => $t['state'] === 'not_open')),
            'announcements' => $announcements, 'resources' => $resources, 'share' => CourseActions::share($offeringId), 'ca' => $grid[0]['total'] ?? 0.0];
    }

    /** @return array<string, mixed> */
    public function task(User $user, string $taskId): array
    {
        $me = $this->me($user);
        $task = DB::table('classroom_tasks')->where('id', $taskId)->where('division_id', $me['division_id'])->whereNull('removed_at')->first();
        if ($task === null) {
            throw new NotFoundHttpException;
        }
        $c = $this->heldCourse($me, Row::text($task, 'offering_id'));
        $now = $this->clock->now();
        $st = Submissions::standing($task, Submissions::events($taskId, [$me['learner_id']])[$me['learner_id']] ?? [], $now);
        if ($st['state'] === 'not_open') {
            throw new NotFoundHttpException;
        }
        $shown = $st['final'] ?? $st['current'];
        $released = ($task->released_at ?? null) !== null;
        $mark = $released ? (Submissions::marks($taskId)[$me['learner_id']] ?? null) : null;
        $ids = array_column($st['history'], 'id');
        $files = Submissions::files('classroom_hand_in', $ids);

        return ['task' => [...$this->views->taskRow($task), 'instructions' => Row::text($task, 'instructions'), 'files' => Submissions::files('classroom_task', [$taskId])[$taskId] ?? []],
            'course' => ['id' => $c['offering']['id'], 'code' => $c['offering']['code'], 'title' => $c['offering']['title']],
            'state' => $st['state'], 'can_hand_in' => $st['can_hand_in'], 'can_withdraw' => $st['can_withdraw'],
            'files' => $shown === null ? [] : ($files[$shown] ?? []),
            'history' => array_map(fn (array $e): array => ['action' => $e['action'], 'late' => $e['late'], 'at' => $e['at'], 'files' => array_column($files[$e['id']] ?? [], 'name')], array_reverse($st['history'])),
            'mark' => $mark === null ? null : ['mark' => $mark['mark'], 'feedback' => $mark['feedback'], 'by' => $mark['by'], 'at' => $mark['at'], 'was' => $mark['was'], 'reason' => $mark['reason'],
                'files' => Submissions::files('classroom_mark', [$mark['id']])[$mark['id']] ?? []]];
    }

    /**
     * Work due in the next two weeks, or open late, for Today.
     *
     * @return list<array{id: string, code: string, title: string, due_at: string, state: string}>
     */
    public function due(User $user): array
    {
        $me = $this->me($user);
        $now = $this->clock->now();
        $out = [];
        foreach ($this->held($me) as $c) {
            foreach ($this->tasks($c['offering']['id']) as $t) {
                $due = CarbonImmutable::parse(Row::text($t, 'due_at'));
                $st = Submissions::standing($t, Submissions::events(Row::text($t, 'id'), [$me['learner_id']])[$me['learner_id']] ?? [], $now);
                if (in_array($st['state'], ['open', 'handed_in', 'late_open'], true) && $due->lessThanOrEqualTo($now->addDays(14))) {
                    $out[] = ['id' => Row::text($t, 'id'), 'code' => $c['offering']['code'], 'title' => Row::text($t, 'title'), 'due_at' => $due->toIso8601String(), 'state' => $st['state']];
                }
            }
        }
        usort($out, fn (array $a, array $b): int => strcmp($a['due_at'], $b['due_at']));

        return $out;
    }

    /** @return Me */
    public function me(User $user): array
    {
        return $this->courses->student($user) ?? throw new NotFoundHttpException;
    }

    /**
     * The courses the student holds a place in, this semester.
     *
     * @param  Me  $me
     * @return list<array{offering: array{id: string, code: string, title: string, group: string, department_id: string, department: string, head_id: string|null, lecturers: list<array{id: string, name: string}>, taken: int}, period: array{id: string, name: string, starts_on: string, ends_on: string}}>
     */
    private function held(array $me): array
    {
        $period = $this->courses->current($me['division_id']);
        if ($period === null) {
            return [];
        }
        $out = [];
        foreach ($this->courses->offerings($me['division_id'], $period['id']) as $o) {
            if ($this->courses->holds($me['learner_id'], $o['id'], $period['id'])) {
                $out[] = ['offering' => $o, 'period' => $period];
            }
        }

        return $out;
    }

    /**
     * @param  Me  $me
     * @return array{offering: array{id: string, code: string, title: string, group: string, department_id: string, department: string, head_id: string|null, lecturers: list<array{id: string, name: string}>, taken: int}, period: array{id: string, name: string, starts_on: string, ends_on: string}}
     */
    private function heldCourse(array $me, string $offeringId): array
    {
        foreach ($this->held($me) as $c) {
            if ($c['offering']['id'] === $offeringId) {
                return $c;
            }
        }
        throw new NotFoundHttpException;
    }

    /** @return list<object> */
    private function tasks(string $offeringId): array
    {
        return array_values(DB::table('classroom_tasks')->where('offering_id', $offeringId)->whereNull('removed_at')->orderBy('due_at')->get()->all());
    }

    private function unread(User $user, string $offeringId): int
    {
        return DB::table('classroom_announcements as a')->where('a.offering_id', $offeringId)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('classroom_announcement_reads as r')->whereColumn('r.announcement_id', 'a.id')->where('r.user_id', $user->id))->count();
    }
}
