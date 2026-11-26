<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Http;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Assessment\Application\Actions\ChoiceActions;
use Acadly\Modules\Assessment\Application\Reach;
use Acadly\Modules\Assessment\Application\SubjectChoices;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Subject choices (D-117, the approved design): one grid per class of the
 * levels that have electives. Core subjects are taken by everyone; the
 * electives are ticked per learner.
 *
 * @phpstan-import-type ClassRow from Reach
 */
final class ChoicesController
{
    public function __construct(
        private readonly Access $access,
        private readonly Reach $reach,
        private readonly AcademicCalendar $calendar,
        private readonly Teaching $teaching,
        private readonly EnrolmentDirectory $learners,
        private readonly SubjectChoices $choices,
        private readonly Clock $clock,
        private readonly SchoolDirectory $school,
    ) {}

    /**
     * `?class_id=` chooses the class; the first the viewer may set otherwise.
     *
     * @response array{classes: list<array{id: string, name: string, level: string}>, class: array{id: string, name: string, level: string}|null, on: bool, core: list<string>, electives: list<array{id: string, short: string, name: string, takers: int}>, learners: list<array{id: string, name: string, takes: list<string>}>, none: int}
     */
    public function index(Request $request): JsonResponse
    {
        $viewer = self::user($request);
        $request->validate(['class_id' => ['nullable', 'uuid']]);
        $classes = array_values(array_filter($this->reach->classes($viewer),
            fn (array $c): bool => $this->reach->may($viewer, AssessmentPermission::Plan, $c['id']) && $this->choices->electives($c) !== []));

        if ($classes === []) {
            $this->access->check($viewer, AssessmentPermission::Plan);
        }

        $wanted = $request->string('class_id')->toString();
        $class = $classes[0] ?? null;

        if ($wanted !== '') {
            $class = null;

            foreach ($classes as $c) {
                $class = $c['id'] === $wanted ? $c : $class;
            }

            $class ?? throw new NotFoundHttpException;
        }

        $out = $this->classOut(...);

        if ($class === null) {
            return response()->json(['classes' => [], 'class' => null, 'on' => false, 'core' => [], 'electives' => [], 'learners' => [], 'none' => 0]);
        }

        $today = $this->clock->today();
        $electives = $this->choices->electives($class);
        $ids = array_column($electives, 'id');
        $on = $this->choices->on($class);
        $here = $this->learners->learnersIn($class['id'], $today);
        $chosen = $this->choices->chosen($class, array_column($here, 'id'));

        // Takers across the level: every class of it this year.
        $level = [];

        foreach ($this->calendar->classesInYear($class['year_id']) as $c) {
            if ($c['division_id'] === $class['division_id'] && $c['level_code'] === $class['level_code']) {
                $level = [...$level, ...array_column($this->learners->learnersIn($c['id'], $today), 'id')];
            }
        }

        $takers = $this->choices->takers($class, $ids, $level);
        $rows = [];
        $none = 0;

        foreach ($here as $l) {
            $takes = $on ? array_values(array_filter($ids, fn (string $s): bool => $chosen[$l['id']][$s] ?? false)) : [];
            $none += $on && $takes === [] ? 1 : 0;
            $rows[] = ['id' => $l['id'], 'name' => $l['name'], 'takes' => $takes];
        }

        return response()->json([
            'classes' => $this->levelTabs($classes, $class),
            'class' => $out($class),
            'on' => $on,
            'core' => array_column(array_values(array_filter($this->teaching->subjectsFor($class['division_id'], $class['level_code']), fn (array $s): bool => $s['core'])), 'name'),
            'electives' => array_map(fn (array $s): array => ['id' => $s['id'], 'short' => $s['short'], 'name' => $s['name'], 'takers' => $on ? count($takers[$s['id']] ?? []) : 0], $electives),
            'learners' => $rows,
            'none' => $none,
        ]);
    }

    /** `{learners: [{learner_id, subject_ids}]}`: the electives each learner of the class takes. */
    public function save(Request $request, string $class, ChoiceActions $actions): JsonResponse
    {
        // The class first: one the viewer may not set is refused before the body is checked.
        $this->access->check(self::user($request), AssessmentPermission::Plan, ScopeRef::of(ScopeType::ClassGroup, $class));
        $request->validate([
            'learners' => ['required', 'array', 'max:200'],
            'learners.*.learner_id' => ['required', 'uuid', 'distinct'],
            'learners.*.subject_ids' => ['present', 'array', 'max:30'],
            'learners.*.subject_ids.*' => ['uuid'],
        ]);
        $takes = [];

        foreach ((array) $request->input('learners') as $row) {
            if (is_array($row) && is_string($row['learner_id'] ?? null) && is_array($row['subject_ids'] ?? null)) {
                $takes[$row['learner_id']] = array_values(array_filter($row['subject_ids'], 'is_string'));
            }
        }

        $changed = $actions->save(self::user($request), $class, $takes);
        $request->query->set('class_id', $class);
        $response = $this->index($request);
        $data = (array) $response->getData(true);

        return response()->json(['changed' => $changed] + $data);
    }

    /**
     * The classes of the same level, as tabs; with the campus where the school has more than one.
     *
     * @param  list<ClassRow>  $classes
     * @param  ClassRow  $class
     * @return list<array{id: string, name: string, level: string}>
     */
    private function levelTabs(array $classes, array $class): array
    {
        $same = array_values(array_filter($classes, fn (array $c): bool => $c['division_id'] === $class['division_id'] && $c['level_code'] === $class['level_code']));
        $campuses = count(array_unique(array_column($same, 'campus_id'))) > 1;
        $out = [];

        foreach ($same as $c) {
            $row = $this->classOut($c);
            $out[] = $campuses ? ['name' => $row['name'].' · '.($this->school->campus($c['campus_id'])->name ?? '')] + $row : $row;
        }

        return $out;
    }

    /**
     * @param  ClassRow  $c
     * @return array{id: string, name: string, level: string}
     */
    private function classOut(array $c): array
    {
        return ['id' => $c['id'], 'name' => $c['name'], 'level' => $this->reach->levelLabel($c['division_id'], $c['level_code'])];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
