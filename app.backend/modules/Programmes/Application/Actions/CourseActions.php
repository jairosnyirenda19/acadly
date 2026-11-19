<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Application\Place;
use Acadly\Modules\Programmes\Contracts\ProgrammesAudit;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The course catalogue (D-097). A course is defined once and used by many
 * programmes. A course with history is retired, not deleted, and a course in
 * a released curriculum keeps its credits.
 */
final class CourseActions
{
    public function __construct(
        private readonly Place $place,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @param  array{id: string|null, department_id: string, code: string, title: string, credits: int, lecture_hours: int, tutorial_hours: int, practical_hours: int, needs: list<string>, retired: bool}  $c */
    public function save(User $by, string $divisionId, array $c): string
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Manage);
        $code = self::code($c['code']);

        if ($code === null || trim($c['title']) === '') {
            throw ValidationException::withMessages(['code' => ['Give the course a code, as ACC 201, and a title.']]);
        }

        if ($c['credits'] < 1 || $c['credits'] > 120 || max($c['lecture_hours'], $c['tutorial_hours'], $c['practical_hours']) > 40 || min($c['lecture_hours'], $c['tutorial_hours'], $c['practical_hours']) < 0) {
            throw ValidationException::withMessages(['credits' => ['Give from 1 to 120 credits, and from 0 to 40 hours a week of each kind.']]);
        }

        $department = DB::table('programme_departments as d')->join('programme_faculties as f', 'f.id', '=', 'd.faculty_id')
            ->where('d.id', $c['department_id'])->where('f.division_id', $divisionId)->first(['d.id', 'd.retired_at']);
        if ($department === null || ($department->retired_at !== null && ! $c['retired'])) {
            throw ValidationException::withMessages(['department_id' => ['Choose a department of this division that is in use.']]);
        }

        if (DB::table('programme_courses')->where('division_id', $divisionId)->where('code', $code)->when($c['id'] !== null, fn ($q) => $q->where('id', '!=', $c['id']))->exists()) {
            throw ValidationException::withMessages(['code' => ["Another course has the code {$code}."]]);
        }

        $needs = array_values(array_unique($c['needs']));
        if ($needs !== [] && DB::table('programme_courses')->where('division_id', $divisionId)->whereIn('id', $needs)->count() !== count($needs)) {
            throw ValidationException::withMessages(['needs' => ['Choose the courses needed first from this catalogue.']]);
        }

        if ($c['id'] !== null) {
            $old = Place::owned('programme_courses', $c['id'], $divisionId);

            if (in_array($c['id'], $needs, true) || $this->leadsBackTo($c['id'], $needs)) {
                throw ValidationException::withMessages(['needs' => ['A course cannot need itself, or a course that needs it.']]);
            }

            $released = DB::table('programme_curriculum_courses as cc')->join('programme_curricula as cu', 'cu.id', '=', 'cc.curriculum_id')
                ->where('cc.course_id', $c['id'])->whereNotNull('cu.released_at')->exists();
            if ($released && Row::int($old, 'credits') !== $c['credits']) {
                throw ValidationException::withMessages(['credits' => ['This course is in a released curriculum, so its credits do not change. Add a new course instead.']]);
            }
        }

        return DB::transaction(function () use ($by, $divisionId, $c, $code, $needs): string {
            $now = $this->clock->now();
            $id = $c['id'] ?? Ids::next();
            $row = ['department_id' => $c['department_id'], 'code' => $code, 'title' => mb_substr(trim($c['title']), 0, 160), 'credits' => $c['credits'],
                'lecture_hours' => $c['lecture_hours'], 'tutorial_hours' => $c['tutorial_hours'], 'practical_hours' => $c['practical_hours'], 'updated_at' => $now];

            if ($c['id'] === null) {
                DB::table('programme_courses')->insert([...$row, 'id' => $id, 'division_id' => $divisionId, 'created_at' => $now, 'retired_at' => $c['retired'] ? $now : null]);
            } else {
                DB::table('programme_courses')->where('id', $id)->update([...$row, 'retired_at' => $c['retired'] ? DB::raw('coalesce(retired_at, now())') : null]);
                DB::table('programme_course_needs')->where('course_id', $id)->delete();
            }

            foreach ($needs as $need) {
                DB::table('programme_course_needs')->insert(['id' => Ids::next(), 'course_id' => $id, 'needs_course_id' => $need]);
            }

            $this->audit->record(ProgrammesAudit::CourseSaved, actor: $this->audit->actorFor($by), meta: ['course' => $id, 'code' => $code]);

            return $id;
        });
    }

    /** Course codes are written one way: letters, a space, the number. */
    public static function code(string $code): ?string
    {
        $code = strtoupper(preg_replace('/\s+/', '', $code) ?? '');

        return preg_match('/^([A-Z]{2,5})([0-9]{2,4}[A-Z]?)$/', $code, $m) === 1 ? "{$m[1]} {$m[2]}" : null;
    }

    /** @param  list<string>  $needs */
    private function leadsBackTo(string $courseId, array $needs): bool
    {
        $seen = [];
        $todo = $needs;

        while ($todo !== []) {
            $next = array_pop($todo);
            if ($next === $courseId) {
                return true;
            }
            if (isset($seen[$next])) {
                continue;
            }
            $seen[$next] = true;

            foreach (DB::table('programme_course_needs')->where('course_id', $next)->get(['needs_course_id']) as $more) {
                $todo[] = Row::text($more, 'needs_course_id');
            }
        }

        return false;
    }
}
