<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Application;

use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Contracts\AwayByDecision;
use Acadly\Modules\Conduct\Domain\Sanction;
use Acadly\Modules\Conduct\Journeys\SeriousIncident\State;
use Illuminate\Support\Facades\DB;

/**
 * Decided suspensions keep a learner out of school (D-111). A suspension is
 * in force once the incident is decided, and stays so after it is closed.
 * Only each learner's latest outcome of an incident counts.
 */
final class Suspensions implements AwayByDecision
{
    public const array DECIDED = [State::Decided->value, State::FamilyMet->value, State::Closed->value];

    public function decisions(array $learnerIds): array
    {
        if ($learnerIds === []) {
            return [];
        }

        $latest = [];

        foreach (DB::table('conduct_outcomes as o')->join('conduct_cases as c', 'c.id', '=', 'o.case_id')->whereIn('o.learner_id', $learnerIds)
            ->whereIn('c.state', self::DECIDED)->orderBy('o.created_at')->get(['o.id', 'o.case_id', 'o.learner_id', 'o.outcome', 'o.starts_on', 'o.days', 'c.number']) as $o) {
            $latest[Row::text($o, 'case_id').'|'.Row::text($o, 'learner_id')] = $o;
        }

        $suspended = array_filter($latest, fn (object $o): bool => Row::text($o, 'outcome') === Sanction::Suspension->value && Row::maybeText($o, 'starts_on') !== null);
        $ends = self::endsOf(array_values(array_map(fn (object $o): string => Row::text($o, 'id'), $suspended)));
        $out = [];

        foreach ($suspended as $o) {
            $out[Row::text($o, 'learner_id')][] = ['label' => 'Suspended', 'reference' => Row::text($o, 'number'), 'starts_on' => Row::text($o, 'starts_on'),
                'days' => Row::int($o, 'days'), 'ended_after' => $ends[Row::text($o, 'id')] ?? null];
        }

        return $out;
    }

    /**
     * The last day of each suspension ended early.
     *
     * @param  list<string>  $outcomeIds
     * @return array<string, string>
     */
    public static function endsOf(array $outcomeIds): array
    {
        $out = [];

        foreach ($outcomeIds === [] ? [] : DB::table('conduct_suspension_ends')->whereIn('outcome_id', $outcomeIds)->orderBy('created_at')->get(['outcome_id', 'ended_after']) as $e) {
            $out[Row::text($e, 'outcome_id')] = Row::text($e, 'ended_after');
        }

        return $out;
    }
}
