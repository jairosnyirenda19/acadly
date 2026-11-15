<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Http;

use Acadly\Core\Academics\Application\Actions\SubjectActions;
use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\AcademicsPermission;
use Acadly\Core\Institution\Contracts\DivisionView;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Core\Institution\Contracts\Term;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The Subjects screen (the approved design): each level the viewer manages, with its subjects. */
final class SubjectsController
{
    public function __construct(
        private readonly Access $access,
        private readonly SchoolDirectory $school,
        private readonly AcademicCalendar $calendar,
    ) {}

    /**
     * @response array{levels: list<array{division_id: string, division: string, level_word: string, subject_word: string, subject_word_plural: string, levels: list<array{code: string, label: string}>, subjects: list<array{id: string, short: string, name: string, core: bool, levels: list<string>, classes: int}>, starter: list<array{short: string, name: string, core: bool, levels: list<string>}>}>}
     */
    public function index(Request $request): JsonResponse
    {
        $viewer = self::user($request);
        $divisions = array_values(array_filter($this->school->divisionViews(),
            fn (DivisionView $d): bool => $this->access->allows($viewer, AcademicsPermission::Manage, ScopeRef::of(ScopeType::Division, $d->id))));

        if ($divisions === []) {
            $this->access->check($viewer, AcademicsPermission::Manage);
        }

        $year = $this->calendar->currentYear();

        return response()->json(['levels' => array_map(fn (DivisionView $d): array => $this->level($d, $year['id'] ?? null), $divisions)]);
    }

    /** Starts a level's subjects from its profile: `{division_id}`. */
    public function start(Request $request, SubjectActions $subjects): JsonResponse
    {
        $subjects->start(self::user($request), $request->string('division_id')->toString());

        return $this->index($request);
    }

    /** Adds a subject: `{division_id, name, short, core, levels}`. */
    public function store(Request $request, SubjectActions $subjects): JsonResponse
    {
        $subjects->save(self::user($request), null, $request->string('division_id')->toString(), $request->string('name')->toString(),
            $request->string('short')->toString(), $request->boolean('core'), self::codes($request->input('levels')));

        return $this->index($request);
    }

    /** Changes a subject: `{division_id, name, short, core, levels}`. */
    public function update(Request $request, string $id, SubjectActions $subjects): JsonResponse
    {
        $subjects->save(self::user($request), $id, $request->string('division_id')->toString(), $request->string('name')->toString(),
            $request->string('short')->toString(), $request->boolean('core'), self::codes($request->input('levels')));

        return $this->index($request);
    }

    /** Retires a subject: `{reason}`. */
    public function retire(Request $request, string $id, SubjectActions $subjects): JsonResponse
    {
        $subjects->retire(self::user($request), $id, $request->string('reason')->toString());

        return $this->index($request);
    }

    /** @return array<string, mixed> */
    private function level(DivisionView $division, ?string $yearId): array
    {
        $classes = [];

        if ($yearId !== null) {
            foreach (DB::table('academic_classes')->where('year_id', $yearId)->where('division_id', $division->id)->whereNull('ended_at')
                ->selectRaw('level_code, count(*) as n')->groupBy('level_code')->get() as $row) {
                $classes[Row::text($row, 'level_code')] = Row::int($row, 'n');
            }
        }

        $subjects = DB::table('academic_subjects')->where('division_id', $division->id)->whereNull('retired_at')->orderBy('position')->get()
            ->map(function (object $s) use ($classes): array {
                $levels = self::codes(json_decode(Row::text($s, 'levels'), true));

                return [
                    'id' => Row::text($s, 'id'), 'short' => Row::text($s, 'short'), 'name' => Row::text($s, 'name'),
                    'core' => (bool) ($s->core ?? false), 'levels' => $levels,
                    'classes' => array_sum(array_map(fn (string $code): int => $classes[$code] ?? 0, $levels)),
                ];
            })->values()->all();

        $started = DB::table('academic_subjects')->where('division_id', $division->id)->exists();

        return [
            'division_id' => $division->id,
            'division' => $division->name,
            'level_word' => $division->profile->word(Term::Level->value),
            'subject_word' => $division->profile->word(Term::Subject->value),
            'subject_word_plural' => $division->profile->word(Term::Subject->value, true),
            'levels' => $division->profile->levels,
            'subjects' => $subjects,
            'starter' => $started ? [] : $division->profile->subjects,
        ];
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
