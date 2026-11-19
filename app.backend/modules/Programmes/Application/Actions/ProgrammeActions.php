<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Application\CurriculumChecks;
use Acadly\Modules\Programmes\Application\Place;
use Acadly\Modules\Programmes\Application\ProgrammesViews;
use Acadly\Modules\Programmes\Contracts\Award;
use Acadly\Modules\Programmes\Contracts\ProgrammesAudit;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Programmes and their curriculum versions (D-097). Only a draft changes. A
 * released version never changes: students keep the version of their intake,
 * and a change is a new version for new intakes.
 */
final class ProgrammeActions
{
    public function __construct(
        private readonly Place $place,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @param  array{id: string|null, department_id: string, code: string, name: string, award: Award, years: int, semesters: int, credits: int, min_load: int, max_load: int, open: bool, retired: bool}  $p */
    public function save(User $by, string $divisionId, array $p): string
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Manage);
        $code = StructureActions::code($p['code']);

        if ($code === null || trim($p['name']) === '') {
            throw ValidationException::withMessages(['code' => ['Give the programme a name and a short code of 2 to 12 capital letters or digits.']]);
        }

        if ($p['years'] < 1 || $p['years'] > 7 || $p['semesters'] < 1 || $p['semesters'] > 3 || $p['credits'] < 1 || $p['credits'] > 1200
            || $p['min_load'] < 1 || $p['max_load'] < $p['min_load'] || $p['max_load'] > 200) {
            throw ValidationException::withMessages(['years' => ['Give 1 to 7 years, 1 to 3 semesters a year, the credits to graduate, and a lowest load no higher than the highest.']]);
        }

        $department = DB::table('programme_departments as d')->join('programme_faculties as f', 'f.id', '=', 'd.faculty_id')
            ->where('d.id', $p['department_id'])->where('f.division_id', $divisionId)->first(['d.id', 'd.retired_at']);
        if ($department === null || ($department->retired_at !== null && ! $p['retired'])) {
            throw ValidationException::withMessages(['department_id' => ['Choose a department of this division that is in use.']]);
        }

        if (DB::table('programmes')->where('division_id', $divisionId)->where('code', $code)->when($p['id'] !== null, fn ($q) => $q->where('id', '!=', $p['id']))->exists()) {
            throw ValidationException::withMessages(['code' => ["Another programme has the code {$code}."]]);
        }

        if ($p['id'] !== null) {
            $old = Place::owned('programmes', $p['id'], $divisionId);
            $released = DB::table('programme_curricula')->where('programme_id', $p['id'])->whereNotNull('released_at')->exists();
            $shape = [Row::int($old, 'years'), Row::int($old, 'semesters'), Row::int($old, 'credits')] !== [$p['years'], $p['semesters'], $p['credits']];

            if ($released && $shape) {
                throw ValidationException::withMessages(['years' => ['A released curriculum depends on the years, semesters and credits. They do not change once a version is released.']]);
            }
        }

        return DB::transaction(function () use ($by, $divisionId, $p, $code): string {
            $now = $this->clock->now();
            $id = $p['id'] ?? Ids::next();
            $row = ['department_id' => $p['department_id'], 'code' => $code, 'name' => mb_substr(trim($p['name']), 0, 160), 'award' => $p['award']->value,
                'years' => $p['years'], 'semesters' => $p['semesters'], 'credits' => $p['credits'], 'min_load' => $p['min_load'], 'max_load' => $p['max_load'],
                'open_for_intake' => $p['open'] && ! $p['retired'], 'updated_at' => $now];

            if ($p['id'] === null) {
                DB::table('programmes')->insert([...$row, 'id' => $id, 'division_id' => $divisionId, 'created_at' => $now, 'retired_at' => $p['retired'] ? $now : null]);
                DB::table('programme_curricula')->insert(['id' => Ids::next(), 'programme_id' => $id, 'label' => $this->label($id), 'created_at' => $now, 'updated_at' => $now]);
            } else {
                DB::table('programmes')->where('id', $id)->update([...$row, 'retired_at' => $p['retired'] ? DB::raw('coalesce(retired_at, now())') : null]);
            }

            $this->audit->record(ProgrammesAudit::ProgrammeSaved, actor: $this->audit->actorFor($by), meta: ['programme' => $id, 'code' => $code]);

            return $id;
        });
    }

    /** A new draft version, copied from the newest released one. */
    public function newVersion(User $by, string $divisionId, string $programmeId): string
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Manage);
        Place::owned('programmes', $programmeId, $divisionId);

        if (DB::table('programme_curricula')->where('programme_id', $programmeId)->whereNull('released_at')->exists()) {
            throw ValidationException::withMessages(['version' => ['This programme already has a draft version. Release it or change it.']]);
        }

        $from = DB::table('programme_curricula')->where('programme_id', $programmeId)->whereNotNull('released_at')->orderByDesc('released_at')->first();

        return DB::transaction(function () use ($by, $programmeId, $from): string {
            $now = $this->clock->now();
            $id = Ids::next();
            $label = $this->label($programmeId);
            DB::table('programme_curricula')->insert(['id' => $id, 'programme_id' => $programmeId, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]);

            if ($from !== null) {
                $groups = [];
                foreach (DB::table('programme_elective_groups')->where('curriculum_id', Row::text($from, 'id'))->get() as $g) {
                    $groups[Row::text($g, 'id')] = Ids::next();
                    DB::table('programme_elective_groups')->insert(['id' => $groups[Row::text($g, 'id')], 'curriculum_id' => $id, 'year' => Row::int($g, 'year'), 'semester' => Row::int($g, 'semester'),
                        'label' => Row::text($g, 'label'), 'choose' => Row::int($g, 'choose'), 'position' => Row::int($g, 'position')]);
                }
                foreach (DB::table('programme_curriculum_courses')->where('curriculum_id', Row::text($from, 'id'))->get() as $c) {
                    $group = Row::maybeText($c, 'group_id');
                    DB::table('programme_curriculum_courses')->insert(['id' => Ids::next(), 'curriculum_id' => $id, 'year' => Row::int($c, 'year'), 'semester' => Row::int($c, 'semester'),
                        'course_id' => Row::text($c, 'course_id'), 'group_id' => $group !== null ? $groups[$group] : null, 'position' => Row::int($c, 'position')]);
                }
            }

            $this->audit->record(ProgrammesAudit::CurriculumDrafted, actor: $this->audit->actorFor($by), meta: ['programme' => $programmeId, 'label' => $label]);

            return $id;
        });
    }

    /**
     * Replaces the courses and elective groups of a draft.
     *
     * @param  list<array{key: string, year: int, semester: int, label: string, choose: int}>  $groups
     * @param  list<array{course_id: string, year: int, semester: int, group: string|null}>  $courses
     */
    public function saveDraft(User $by, string $divisionId, string $curriculumId, array $groups, array $courses): void
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Manage);
        [$curriculum, $programme] = $this->draft($curriculumId, $divisionId);
        $cid = Row::text($curriculum, 'id');
        $inRange = static fn (int $y, int $s): bool => $y >= 1 && $y <= Row::int($programme, 'years') && $s >= 1 && $s <= Row::int($programme, 'semesters');

        $keys = [];
        foreach ($groups as $g) {
            if (! $inRange($g['year'], $g['semester']) || trim($g['label']) === '' || $g['choose'] < 1 || $g['choose'] > 10 || isset($keys[$g['key']])) {
                throw ValidationException::withMessages(['groups' => ['Give each elective group a name, its year and semester, and how many courses to choose.']]);
            }
            $keys[$g['key']] = [$g['year'], $g['semester']];
        }

        $seen = [];
        foreach ($courses as $c) {
            if (! $inRange($c['year'], $c['semester']) || isset($seen[$c['course_id']])) {
                throw ValidationException::withMessages(['courses' => ['Give each course once, in a year and semester of the programme.']]);
            }
            if ($c['group'] !== null && ($keys[$c['group']] ?? null) !== [$c['year'], $c['semester']]) {
                throw ValidationException::withMessages(['courses' => ['An elective course sits in its group\'s year and semester.']]);
            }
            $seen[$c['course_id']] = true;
        }

        if ($seen !== [] && DB::table('programme_courses')->where('division_id', $divisionId)->whereIn('id', array_keys($seen))->count() !== count($seen)) {
            throw ValidationException::withMessages(['courses' => ['Choose the courses from this division\'s catalogue.']]);
        }

        DB::transaction(function () use ($by, $cid, $groups, $courses): void {
            DB::table('programme_curriculum_courses')->where('curriculum_id', $cid)->delete();
            DB::table('programme_elective_groups')->where('curriculum_id', $cid)->delete();
            $ids = [];

            foreach ($groups as $i => $g) {
                $ids[$g['key']] = Ids::next();
                DB::table('programme_elective_groups')->insert(['id' => $ids[$g['key']], 'curriculum_id' => $cid, 'year' => $g['year'], 'semester' => $g['semester'],
                    'label' => mb_substr(trim($g['label']), 0, 40), 'choose' => $g['choose'], 'position' => $i + 1]);
            }
            foreach ($courses as $i => $c) {
                DB::table('programme_curriculum_courses')->insert(['id' => Ids::next(), 'curriculum_id' => $cid, 'year' => $c['year'], 'semester' => $c['semester'],
                    'course_id' => $c['course_id'], 'group_id' => $c['group'] !== null ? $ids[$c['group']] : null, 'position' => $i + 1]);
            }
            DB::table('programme_curricula')->where('id', $cid)->update(['updated_at' => $this->clock->now()]);

            $this->audit->record(ProgrammesAudit::CurriculumSaved, actor: $this->audit->actorFor($by), meta: ['curriculum' => $cid, 'courses' => count($courses)]);
        });
    }

    /** Releases a draft that passes every check. From then on it never changes. */
    public function release(User $by, string $divisionId, string $curriculumId): void
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Manage);
        [$curriculum, $programme] = $this->draft($curriculumId, $divisionId);
        $cid = Row::text($curriculum, 'id');
        $parts = ProgrammesViews::parts($cid);
        $checks = CurriculumChecks::run(ProgrammesViews::shape($programme), $parts['groups'], $parts['courses']);

        if ($checks['problems'] !== []) {
            throw ValidationException::withMessages(['checks' => ['The curriculum does not pass every check yet.']]);
        }

        $label = Row::text($curriculum, 'label');
        DB::transaction(function () use ($by, $cid, $label): void {
            DB::table('programme_curricula')->where('id', $cid)->whereNull('released_at')
                ->update(['released_at' => $this->clock->now(), 'released_by' => $by->id, 'updated_at' => $this->clock->now()]);
            $this->audit->record(ProgrammesAudit::CurriculumReleased, actor: $this->audit->actorFor($by), meta: ['curriculum' => $cid, 'label' => $label]);
        });
    }

    /** @return array{0: object, 1: object} */
    private function draft(string $curriculumId, string $divisionId): array
    {
        $curriculum = DB::table('programme_curricula')->where('id', $curriculumId)->first();
        $programme = $curriculum !== null ? DB::table('programmes')->where('id', $curriculum->programme_id)->where('division_id', $divisionId)->first() : null;

        if ($curriculum === null || $programme === null) {
            abort(404);
        }

        if ($curriculum->released_at !== null) {
            throw ValidationException::withMessages(['version' => ['A released version does not change. Make a new version.']]);
        }

        return [$curriculum, $programme];
    }

    /** The year's number, then .2, .3 for a second and third version in one year. */
    private function label(string $programmeId): string
    {
        $year = CarbonImmutable::parse($this->clock->now())->format('Y');
        $taken = DB::table('programme_curricula')->where('programme_id', $programmeId)->pluck('label')->all();

        for ($n = 1; ; $n++) {
            $label = $n === 1 ? $year : "{$year}.{$n}";
            if (! in_array($label, $taken, true)) {
                return $label;
            }
        }
    }
}
