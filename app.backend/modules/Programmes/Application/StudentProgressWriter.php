<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Application\Actions\StudentActions;
use Acadly\Modules\Programmes\Contracts\StudentProgress;
use Acadly\Modules\Programmes\Contracts\StudentStatus;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** The Programmes contract Results applies its board decisions through (D-106). */
final class StudentProgressWriter implements StudentProgress
{
    public function __construct(
        private readonly StudentActions $students,
        private readonly Clock $clock,
    ) {}

    public function programmes(string $divisionId): array
    {
        return array_values(array_map(fn (object $p): array => ['id' => Row::text($p, 'id'), 'code' => Row::text($p, 'code'), 'name' => Row::text($p, 'name'),
            'award' => Row::text($p, 'award'), 'years' => Row::int($p, 'years'), 'credits' => Row::int($p, 'credits')],
            DB::table('programmes')->where('division_id', $divisionId)->whereNull('retired_at')->orderBy('code')->get(['id', 'code', 'name', 'award', 'years', 'credits'])->all()));
    }

    public function students(string $programmeId, ?int $year): array
    {
        $q = DB::table('programme_students')->where('programme_id', $programmeId)->whereNull('ended_at')->where('status', StudentStatus::Active->value);
        if ($year !== null) {
            $q->where('year_of_study', $year);
        }

        return array_values(array_unique(array_map(fn (mixed $v): string => is_string($v) ? $v : '', $q->pluck('learner_id')->all())));
    }

    public function coreCourses(string $learnerId): array
    {
        $curriculum = DB::table('programme_students')->where('learner_id', $learnerId)->orderByRaw('ended_at is null desc')->orderByDesc('created_at')->value('curriculum_id');
        if (! is_string($curriculum)) {
            return [];
        }

        return array_values(array_map(fn (object $c): array => ['course_id' => Row::text($c, 'course_id'), 'code' => Row::text($c, 'code')],
            DB::table('programme_curriculum_courses as cc')->join('programme_courses as c', 'c.id', '=', 'cc.course_id')->where('cc.curriculum_id', $curriculum)
                ->whereNull('cc.group_id')->orderBy('cc.year')->orderBy('cc.semester')->orderBy('cc.position')->get(['cc.course_id', 'c.code'])->all()));
    }

    public function apply(User $by, string $learnerId, string $decision, array $carriedCourseIds, string $reason): void
    {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new InvalidArgumentException("[{$decision}] is not a progression decision.");
        }
        $row = DB::table('programme_students as s')->join('programmes as p', 'p.id', '=', 's.programme_id')->where('s.learner_id', $learnerId)->whereNull('s.ended_at')
            ->first(['s.id', 's.division_id', 's.year_of_study', 'p.years']);
        if ($row === null || $decision === 'complete') {
            return;
        }
        $divisionId = Row::text($row, 'division_id');
        $year = Row::int($row, 'year_of_study');
        match ($decision) {
            'proceed', 'carry' => $this->students->changeTrusted($by, $divisionId, Row::text($row, 'id'), StudentStatus::Active, min($year + 1, Row::int($row, 'years')), $reason),
            'repeat_year' => $this->students->changeTrusted($by, $divisionId, Row::text($row, 'id'), StudentStatus::Active, $year, $reason),
            'discontinue' => $this->students->changeTrusted($by, $divisionId, Row::text($row, 'id'), StudentStatus::Discontinued, $year, $reason),
            default => $this->students->changeTrusted($by, $divisionId, Row::text($row, 'id'), StudentStatus::Completed, $year, $reason),
        };
        $now = $this->clock->now();
        foreach (array_values(array_unique($carriedCourseIds)) as $course) {
            DB::table('programme_carried_courses')->insert(['id' => Ids::next(), 'division_id' => $divisionId, 'learner_id' => $learnerId, 'course_id' => $course,
                'reason' => mb_substr($reason, 0, 255), 'created_by' => $by->id, 'created_at' => $now]);
        }
    }

    /**
     * The courses a learner carries and has not taken since: each joins the next registration that offers it.
     *
     * @return list<string>
     */
    public static function carried(string $learnerId): array
    {
        $out = [];
        foreach (DB::table('programme_carried_courses')->where('learner_id', $learnerId)->orderBy('created_at')->get(['course_id', 'created_at']) as $c) {
            $taken = DB::table('programme_registration_courses as rc')->join('programme_registrations as r', function (JoinClause $j): void {
                $j->on('r.id', '=', 'rc.registration_id')->on('r.version', '=', 'rc.version');
            })->where('r.learner_id', $learnerId)->where('rc.course_id', Row::text($c, 'course_id'))->where('r.state', '<>', 'withdrawn')
                ->where('r.created_at', '>', Row::text($c, 'created_at'))->exists();
            if (! $taken) {
                $out[] = Row::text($c, 'course_id');
            }
        }

        return array_values(array_unique($out));
    }
}
