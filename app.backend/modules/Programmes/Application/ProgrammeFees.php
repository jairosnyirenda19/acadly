<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application;

use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Contracts\StudentProgrammes;
use Acadly\Modules\Programmes\Journeys\Registration\State;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** What term fees need of the college (D-116): its programmes, and each student's programme, mode and registered credits. */
final class ProgrammeFees implements StudentProgrammes
{
    public function programmes(string $divisionId): array
    {
        $out = [];

        foreach (DB::table('programmes')->where('division_id', $divisionId)->orderBy('code')->get(['id', 'code', 'name']) as $p) {
            $out[] = ['id' => Row::text($p, 'id'), 'code' => Row::text($p, 'code'), 'name' => Row::text($p, 'name')];
        }

        return $out;
    }

    public function students(array $learnerIds, string $periodId): array
    {
        if ($learnerIds === []) {
            return [];
        }

        $credits = [];

        foreach (DB::table('programme_registrations as r')->join('programme_registration_courses as c', fn (JoinClause $j) => $j->on('c.registration_id', '=', 'r.id')->on('c.version', '=', 'r.version'))
            ->where('r.period_id', $periodId)->whereIn('r.learner_id', $learnerIds)->whereIn('r.state', [State::Approved->value, State::Registered->value])
            ->groupBy('r.learner_id')->get(['r.learner_id', DB::raw('sum(c.credits) as credits')]) as $r) {
            $credits[Row::text($r, 'learner_id')] = Row::int($r, 'credits');
        }

        $out = [];

        foreach (DB::table('programme_students')->whereIn('learner_id', $learnerIds)->whereNull('ended_at')->get(['learner_id', 'programme_id', 'mode']) as $s) {
            $id = Row::text($s, 'learner_id');
            $out[$id] = ['programme_id' => Row::text($s, 'programme_id'), 'mode' => Row::text($s, 'mode'), 'credits' => $credits[$id] ?? null];
        }

        return $out;
    }
}
