<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Http;

use Acadly\Core\Academics\Application\Actions\SaveAcademicYear;
use Acadly\Core\Academics\Application\Actions\SaveClasses;
use Acadly\Core\Academics\Application\Calendar;
use Acadly\Core\Academics\Contracts\AcademicsPermission;
use Acadly\Core\Academics\Domain\AcademicYear;
use Acadly\Core\Institution\Contracts\CampusView;
use Acadly\Core\Institution\Contracts\DivisionView;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Core\Institution\Contracts\Term;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AcademicsController
{
    public function __construct(
        private readonly Calendar $calendar,
        private readonly SchoolDirectory $school,
    ) {}

    /**
     * The academic year, its terms, and the classes of each level at each campus.
     *
     * @response array{year: array{id: string, name: string, starts_on: string, ends_on: string, periods: list<array{id: string, name: string, starts_on: string, ends_on: string}>}|null, periods_per_year: int|null, period_word: string, offerings: list<array{division_id: string, division: string, campus_id: string, campus: string, level_word: string, group_word: string, levels: list<array{code: string, label: string, streams: list<string>}>, classes: int}>, classes: int}
     */
    public function show(Request $request, Access $access): JsonResponse
    {
        $access->check(self::user($request), AcademicsPermission::Manage);

        $year = $this->calendar->currentYear();
        $divisions = [];
        $campuses = [];

        foreach ($this->school->divisionViews() as $division) {
            $divisions[$division->id] = $division;
        }

        foreach ($this->school->campusViews() as $campus) {
            $campuses[$campus->id] = $campus;
        }

        $offerings = [];

        foreach ($this->school->offerings() as $offering) {
            $division = $divisions[$offering['division_id']] ?? null;
            $campus = $campuses[$offering['campus_id']] ?? null;

            if (! $division instanceof DivisionView || ! $campus instanceof CampusView) {
                continue;
            }

            $classes = $year !== null ? $this->calendar->classesOf($year['id'], $division->id, $campus->id) : [];
            $count = 0;

            foreach ($classes as $streams) {
                $count += count($streams);
            }

            $offerings[] = [
                'division_id' => $division->id,
                'division' => $division->name,
                'campus_id' => $campus->id,
                'campus' => $campus->name,
                'level_word' => $division->profile->word(Term::Level->value),
                'group_word' => $division->profile->word(Term::Group->value, true),
                'levels' => array_map(fn (array $level): array => [
                    'code' => $level['code'],
                    'label' => $level['label'],
                    'streams' => array_values(array_filter($classes[$level['code']] ?? [], 'is_string')),
                ], $division->profile->levels),
                'classes' => $count,
            ];
        }

        $first = array_values($divisions)[0] ?? null;

        return response()->json([
            'year' => $year,
            'periods_per_year' => $first?->profile->periodsPerYear,
            'period_word' => $first !== null ? $first->profile->word(Term::Period->value) : 'Term',
            'offerings' => $offerings,
            'classes' => $year !== null ? $this->calendar->classCount($year['id']) : 0,
        ]);
    }

    /** Sets the academic year and its terms: `{name, starts_on, ends_on, terms: [{name, starts_on, ends_on}]}`. */
    public function year(Request $request, SaveAcademicYear $save, Access $access): JsonResponse
    {
        $current = $this->calendar->currentYear();
        $year = $current !== null ? AcademicYear::query()->find($current['id']) : null;

        $save->handle(self::user($request), $year, $request->only(['name', 'starts_on', 'ends_on', 'terms']));

        return $this->show($request, $access);
    }

    /** Sets the classes of one level at one campus: `{division_id, campus_id, streams: {LEVEL: [names]}}`. */
    public function classes(Request $request, SaveClasses $save, Access $access): JsonResponse
    {
        $request->validate(['division_id' => ['required', 'uuid'], 'campus_id' => ['required', 'uuid'], 'streams' => ['present', 'array']]);
        $current = $this->calendar->currentYear();
        $year = $current !== null ? AcademicYear::query()->find($current['id']) : null;

        if (! $year instanceof AcademicYear) {
            throw ValidationException::withMessages(['year' => ['Set the academic year first.']]);
        }

        $streams = [];

        foreach ((array) $request->input('streams') as $code => $names) {
            if (is_string($code) && is_array($names)) {
                $streams[$code] = array_values(array_filter($names, 'is_string'));
            }
        }

        $save->handle(self::user($request), $year, $request->string('division_id')->toString(), $request->string('campus_id')->toString(), $streams);

        return $this->show($request, $access);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
