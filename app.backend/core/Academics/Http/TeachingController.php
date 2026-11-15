<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Http;

use Acadly\Core\Academics\Application\Actions\TeachingActions;
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
 * The Teaching screen (the approved design): for one level at one campus,
 * each class with its class teacher and the teacher of each subject it takes.
 * The tabs are the levels at campuses the viewer manages.
 */
final class TeachingController
{
    public function __construct(
        private readonly Access $access,
        private readonly SchoolDirectory $school,
        private readonly AcademicCalendar $calendar,
    ) {}

    /**
     * `?division_id=&campus_id=` chooses the tab; the first the viewer manages otherwise.
     *
     * @response array{tabs: list<array{division_id: string, campus_id: string, label: string, not_given: int}>, current: array{division_id: string, campus_id: string, label: string}|null, year: array{id: string, name: string}|null, earlier_year: string|null, subjects: list<array{id: string, short: string, name: string, levels: list<string>}>, classes: list<array{id: string, name: string, level_code: string, class_teacher: array{id: string, name: string}|null, teaching: array<string, array{id: string, name: string}|null>}>, teachers: list<array{id: string, name: string, classes: list<string>, teaches: list<string>}>, totals: array{classes: int, given: int, places: int, not_given: int, teachers: int}}
     */
    public function index(Request $request): JsonResponse
    {
        $viewer = self::user($request);
        $year = $this->calendar->currentYear();
        $tabs = [];

        foreach ($this->school->offerings() as $offering) {
            if (! $this->access->allows($viewer, AcademicsPermission::Manage, ScopeRef::of(ScopeType::Offering, $offering['id']))) {
                continue;
            }

            $tabs[] = $offering + ['label' => $this->label($offering['division_id'], $offering['campus_id'])];
        }

        if ($tabs === []) {
            $this->access->check($viewer, AcademicsPermission::Manage);
        }

        $request->validate(['division_id' => ['nullable', 'uuid'], 'campus_id' => ['nullable', 'uuid']]);
        $wanted = $request->string('division_id')->toString();
        $current = $tabs[0] ?? null;

        if ($wanted !== '') {
            $current = null;

            foreach ($tabs as $tab) {
                if ($tab['division_id'] === $wanted && $tab['campus_id'] === $request->string('campus_id')->toString()) {
                    $current = $tab;
                }
            }

            if ($current === null) {
                throw new NotFoundHttpException;
            }
        }

        $board = $current !== null && $year !== null ? $this->board($year['id'], $current['division_id'], $current['campus_id']) : null;
        $earlier = $year !== null ? DB::table('academic_years')->where('ends_on', '<', $year['starts_on'])->orderByDesc('ends_on')->value('name') : null;

        return response()->json([
            'tabs' => array_map(fn (array $tab): array => [
                'division_id' => $tab['division_id'], 'campus_id' => $tab['campus_id'], 'label' => $tab['label'],
                'not_given' => $year !== null ? $this->board($year['id'], $tab['division_id'], $tab['campus_id'])['totals']['not_given'] : 0,
            ], $tabs),
            'current' => $current !== null ? ['division_id' => $current['division_id'], 'campus_id' => $current['campus_id'], 'label' => $current['label']] : null,
            'year' => $year !== null ? ['id' => $year['id'], 'name' => $year['name']] : null,
            'earlier_year' => is_string($earlier) ? $earlier : null,
            ...($board ?? ['subjects' => [], 'classes' => [], 'teachers' => [], 'totals' => ['classes' => 0, 'given' => 0, 'places' => 0, 'not_given' => 0, 'teachers' => 0]]),
        ]);
    }

    /** Gives a subject in a class, or the class (no subject), to a teacher or to nobody: `{class_id, subject_id?, user_id?}`. */
    public function give(Request $request, TeachingActions $teaching): JsonResponse
    {
        $teaching->give(self::user($request), $request->string('class_id')->toString(),
            $request->filled('subject_id') ? $request->string('subject_id')->toString() : null,
            $request->filled('user_id') ? $request->string('user_id')->toString() : null);

        $class = DB::table('academic_classes')->where('id', $request->string('class_id')->toString())->first(['division_id', 'campus_id']);
        $request->query->set('division_id', $class !== null ? Row::text($class, 'division_id') : null);
        $request->query->set('campus_id', $class !== null ? Row::text($class, 'campus_id') : null);

        return $this->index($request);
    }

    /** Copies who taught what in the year before: `{division_id, campus_id}`. */
    public function copy(Request $request, TeachingActions $teaching): JsonResponse
    {
        $viewer = self::user($request);
        $offering = $this->school->offeringId($request->string('division_id')->toString(), $request->string('campus_id')->toString());

        if ($offering === null) {
            $this->access->check($viewer, AcademicsPermission::Manage);

            throw new NotFoundHttpException;
        }

        $teaching->copy($viewer, $request->string('division_id')->toString(), $request->string('campus_id')->toString(), $offering);
        $request->query->set('division_id', $request->string('division_id')->toString());
        $request->query->set('campus_id', $request->string('campus_id')->toString());

        return $this->index($request);
    }

    /** @return array{subjects: list<array<string, mixed>>, classes: list<array<string, mixed>>, teachers: list<array<string, mixed>>, totals: array{classes: int, given: int, places: int, not_given: int, teachers: int}} */
    private function board(string $yearId, string $divisionId, string $campusId): array
    {
        $names = DB::table('users')->pluck('name', 'id')->all();
        $subjects = array_values(DB::table('academic_subjects')->where('division_id', $divisionId)->whereNull('retired_at')->orderBy('position')->get()
            ->map(fn (object $s): array => ['id' => Row::text($s, 'id'), 'short' => Row::text($s, 'short'), 'name' => Row::text($s, 'name'),
                'levels' => self::codes(json_decode(Row::text($s, 'levels'), true))])->all());
        $classRows = DB::table('academic_classes')->where('year_id', $yearId)->where('division_id', $divisionId)->where('campus_id', $campusId)
            ->whereNull('ended_at')->orderBy('position')->get(['id', 'name', 'level_code']);

        $teaching = [];

        foreach (DB::table('academic_teaching')->whereIn('class_id', $classRows->pluck('id')->all())->whereNull('ended_at')->get(['class_id', 'subject_id', 'user_id']) as $row) {
            $teaching[Row::text($row, 'class_id')][Row::maybeText($row, 'subject_id') ?? ''] = Row::text($row, 'user_id');
        }

        $person = fn (?string $id): ?array => $id === null ? null : ['id' => $id, 'name' => is_string($names[$id] ?? null) ? $names[$id] : ''];
        $classes = [];
        $teachers = [];
        $given = 0;
        $places = 0;

        foreach ($classRows as $row) {
            $id = Row::text($row, 'id');
            $level = Row::text($row, 'level_code');
            $cells = [];
            $places++;
            $given += isset($teaching[$id]['']) ? 1 : 0;

            foreach ($subjects as $subject) {
                if (in_array($level, $subject['levels'], true)) {
                    $cells[$subject['id']] = $person($teaching[$id][$subject['id']] ?? null);
                    $places++;
                    $given += isset($teaching[$id][$subject['id']]) ? 1 : 0;
                }
            }

            $classes[] = ['id' => $id, 'name' => Row::text($row, 'name'), 'level_code' => $level, 'class_teacher' => $person($teaching[$id][''] ?? null), 'teaching' => (object) $cells];

            foreach ($this->access->holderIds(AcademicsPermission::Teach, ScopeRef::of(ScopeType::ClassGroup, $id)) as $teacher) {
                $teachers[$teacher]['classes'][] = $id;
            }
        }

        $short = array_column($subjects, 'short', 'id');
        $list = [];

        foreach ($teachers as $id => $t) {
            $teaches = [];

            foreach ($teaching as $cells) {
                foreach ($cells as $subject => $who) {
                    if ($who === $id && $subject !== '') {
                        $teaches[$short[$subject] ?? ''] = true;
                    }
                }
            }

            $list[] = ['id' => (string) $id, 'name' => is_string($names[$id] ?? null) ? $names[$id] : '', 'classes' => $t['classes'], 'teaches' => array_keys($teaches)];
        }

        usort($list, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'subjects' => $subjects,
            'classes' => $classes,
            'teachers' => $list,
            'totals' => ['classes' => count($classes), 'given' => $given, 'places' => $places, 'not_given' => $places - $given, 'teachers' => count($list)],
        ];
    }

    private function label(string $divisionId, string $campusId): string
    {
        return ($this->school->division($divisionId)->name ?? '').' · '.($this->school->campus($campusId)->name ?? '');
    }

    /** @return list<string> */
    private static function codes(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
