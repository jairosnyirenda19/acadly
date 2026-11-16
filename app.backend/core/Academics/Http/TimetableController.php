<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Http;

use Acadly\Core\Academics\Application\Actions\TimetableActions;
use Acadly\Core\Academics\Application\Timetables;
use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\AcademicsPermission;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Timetable screens (the approved designs): the bell times of a level at
 * a campus, and the week of one class. The tabs are the levels at campuses
 * the viewer manages.
 */
final class TimetableController
{
    public function __construct(
        private readonly Access $access,
        private readonly SchoolDirectory $school,
        private readonly AcademicCalendar $calendar,
        private readonly Timetables $timetables,
    ) {}

    /**
     * `?division_id=&campus_id=&class_id=` choose the tab and the class; the first of each otherwise.
     *
     * @response array{tabs: list<array{division_id: string, campus_id: string, label: string}>, current: array{division_id: string, campus_id: string, label: string}|null, year: array{id: string, name: string}|null, plans: list<array{name: string, weekdays: list<int>, periods: list<array{name: string, starts: string, ends: string, kind: 'lesson'|'break'}>}>, classes: list<array{id: string, name: string}>, class: array{id: string, name: string}|null, days: list<array{weekday: int, lessons: int, plan: string}>, subjects: list<array{id: string, short: string, name: string}>, teachers: list<array{id: string, name: string}>, teaching: array<string, string>, grid: list<array{weekday: int, period: int, subject_id: string, user_id: string|null}>, counts: list<array{subject_id: string, periods: int}>, free: int}
     */
    public function index(Request $request): JsonResponse
    {
        $viewer = self::user($request);
        $tabs = [];

        foreach ($this->school->offerings() as $offering) {
            if ($this->access->allows($viewer, AcademicsPermission::Manage, ScopeRef::of(ScopeType::Offering, $offering['id']))) {
                $tabs[] = ['division_id' => $offering['division_id'], 'campus_id' => $offering['campus_id'],
                    'label' => ($this->school->division($offering['division_id'])->name ?? '').' · '.($this->school->campus($offering['campus_id'])->name ?? '')];
            }
        }

        if ($tabs === []) {
            $this->access->check($viewer, AcademicsPermission::Manage);
        }

        $request->validate(['division_id' => ['nullable', 'uuid'], 'campus_id' => ['nullable', 'uuid'], 'class_id' => ['nullable', 'uuid']]);
        $current = $tabs[0] ?? null;

        if ($request->filled('division_id')) {
            $current = null;

            foreach ($tabs as $tab) {
                if ($tab['division_id'] === $request->string('division_id')->toString() && $tab['campus_id'] === $request->string('campus_id')->toString()) {
                    $current = $tab;
                }
            }

            if ($current === null) {
                throw new NotFoundHttpException;
            }
        }

        $year = $this->calendar->currentYear();
        $plans = $current !== null ? $this->timetables->plansOf($current['division_id'], $current['campus_id']) : [];
        $classes = [];

        if ($year !== null && $current !== null) {
            foreach ($this->calendar->classesInYear($year['id']) as $c) {
                if ($c['division_id'] === $current['division_id'] && $c['campus_id'] === $current['campus_id']) {
                    $classes[] = $c;
                }
            }
        }

        $class = $classes[0] ?? null;

        if ($request->filled('class_id')) {
            $class = array_values(array_filter($classes, fn (array $c): bool => $c['id'] === $request->string('class_id')->toString()))[0] ?? null;

            if ($class === null) {
                throw new NotFoundHttpException;
            }
        }

        $days = [];

        foreach ($plans as $plan) {
            foreach ($plan['weekdays'] as $d) {
                $days[] = ['weekday' => $d, 'lessons' => count(array_filter($plan['periods'], fn (array $p): bool => $p['kind'] === 'lesson')), 'plan' => $plan['name']];
            }
        }

        usort($days, fn (array $a, array $b): int => $a['weekday'] <=> $b['weekday']);

        return response()->json([
            'tabs' => $tabs,
            'current' => $current,
            'year' => $year !== null ? ['id' => $year['id'], 'name' => $year['name']] : null,
            'plans' => array_map(fn (array $p): array => ['name' => $p['name'], 'weekdays' => $p['weekdays'], 'periods' => $p['periods']], $plans),
            'classes' => array_map(fn (array $c): array => ['id' => $c['id'], 'name' => $c['name']], $classes),
            'class' => $class !== null ? ['id' => $class['id'], 'name' => $class['name']] : null,
            'days' => $days,
            ...($class !== null ? $this->week($class, $days) : ['subjects' => [], 'teachers' => [], 'teaching' => (object) [], 'grid' => [], 'counts' => [], 'free' => 0]),
        ]);
    }

    /** Replaces the bell times of a level at a campus: `{division_id, campus_id, plans: [{name, weekdays, periods: [{name, starts, ends, kind}]}]}`. */
    public function bells(Request $request, TimetableActions $actions): JsonResponse
    {
        $plans = $request->input('plans');
        $actions->saveBells(self::user($request), $request->string('division_id')->toString(), $request->string('campus_id')->toString(),
            is_array($plans) ? array_values(array_filter($plans, is_array(...))) : []);

        $request->query->set('division_id', $request->string('division_id')->toString());
        $request->query->set('campus_id', $request->string('campus_id')->toString());

        return $this->index($request);
    }

    /** Sets one period of a class: `{class_id, weekday, period, subject_id?, user_id?}`. No subject makes it a free period. */
    public function period(Request $request, TimetableActions $actions): JsonResponse
    {
        $classId = $request->string('class_id')->toString();
        $actions->setPeriod(self::user($request), $classId, $request->integer('weekday'), $request->integer('period'),
            $request->filled('subject_id') ? $request->string('subject_id')->toString() : null,
            $request->filled('user_id') ? $request->string('user_id')->toString() : null);

        $class = $this->calendar->classInForce($classId);
        $request->query->set('division_id', $class['division_id'] ?? null);
        $request->query->set('campus_id', $class['campus_id'] ?? null);
        $request->query->set('class_id', $classId);

        return $this->index($request);
    }

    /**
     * @param  array{id: string, level_code: string, division_id: string}  $class
     * @param  list<array{weekday: int, lessons: int, plan: string}>  $days
     * @return array<string, mixed>
     */
    private function week(array $class, array $days): array
    {
        $subjects = [];

        foreach (DB::table('academic_subjects')->where('division_id', $class['division_id'])->whereNull('retired_at')->orderBy('position')->get() as $s) {
            $levels = json_decode(Row::text($s, 'levels'), true);

            if (is_array($levels) && in_array($class['level_code'], $levels, true)) {
                $subjects[] = ['id' => Row::text($s, 'id'), 'short' => Row::text($s, 'short'), 'name' => Row::text($s, 'name')];
            }
        }

        $holders = $this->access->holderIds(AcademicsPermission::Teach, ScopeRef::of(ScopeType::ClassGroup, $class['id']));
        $teachers = array_values(DB::table('users')->whereIn('id', $holders === [] ? ['00000000-0000-0000-0000-000000000000'] : $holders)->orderBy('name')->get(['id', 'name'])
            ->map(fn (object $u): array => ['id' => Row::text($u, 'id'), 'name' => Row::text($u, 'name')])->all());
        $teaching = DB::table('academic_teaching')->where('class_id', $class['id'])->whereNotNull('subject_id')->whereNull('ended_at')->pluck('user_id', 'subject_id')->all();
        $grid = array_values(DB::table('academic_timetable')->where('class_id', $class['id'])->whereNull('ends_on')->orderBy('weekday')->orderBy('period')->get()
            ->map(fn (object $t): array => ['weekday' => Row::int($t, 'weekday'), 'period' => Row::int($t, 'period'),
                'subject_id' => Row::text($t, 'subject_id'), 'user_id' => Row::maybeText($t, 'user_id')])->all());
        $lessons = array_column($days, 'lessons', 'weekday');
        $grid = array_values(array_filter($grid, fn (array $g): bool => $g['period'] <= ($lessons[$g['weekday']] ?? 0)));
        $counts = array_count_values(array_column($grid, 'subject_id'));

        return [
            'subjects' => $subjects,
            'teachers' => $teachers,
            'teaching' => (object) array_filter($teaching, is_string(...)),
            'grid' => $grid,
            'counts' => array_map(fn (string $id, int $n): array => ['subject_id' => $id, 'periods' => $n], array_keys($counts), array_values($counts)),
            'free' => array_sum($lessons) - count($grid),
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
