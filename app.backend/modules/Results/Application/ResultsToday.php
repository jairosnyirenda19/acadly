<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Acadly\Modules\Results\Journeys\Sheet\State;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Results on Today (D-105, D-100): for a lecturer, the sheets of their own
 * courses still to send in the last three weeks of the semester; for a head
 * of department, the sheets waiting for moderation; for the board, those
 * waiting for approval; for the registrar, those ready to release.
 */
final class ResultsToday implements TodayItems
{
    public function __construct(
        private readonly Sheets $sheets,
        private readonly Clock $clock,
    ) {}

    public function key(): string
    {
        return 'results';
    }

    public function today(DashboardScope $scope): array
    {
        if ($scope->school) {
            return [];
        }
        $items = [];
        $toSend = 0;
        foreach ($scope->sees(ResultsPermission::Enter) as $d) {
            $period = $this->sheets->current($d);
            if ($period === null || CarbonImmutable::parse($this->clock->today())->lt(CarbonImmutable::parse($period['ends_on'])->subDays(21))) {
                continue;
            }
            foreach ($this->sheets->offerings($d, $period['id']) as $o) {
                if (! $this->sheets->teaches($scope->viewer, ['offering' => $o, 'period' => $period, 'division_id' => $d])) {
                    continue;
                }
                $s = $this->sheets->sheet($o['id'], 'main');
                $toSend += $s === null || $s->state === State::Entered->value ? 1 : 0;
            }
        }
        if ($toSend > 0) {
            $items[] = ['key' => 'results_enter', 'order' => 13, 'title' => 'Exam marks to send', 'sub' => $toSend === 1 ? 'In 1 course' : "In {$toSend} courses",
                'count' => (string) $toSend, 'tone' => 'attention', 'href' => '/results'];
        }
        foreach ([[ResultsPermission::Moderate, State::Submitted, 'results_moderate', 'Result sheets to moderate', '/results'],
            [ResultsPermission::Board, State::Moderated, 'results_board', 'Result sheets for the board', '/results/board'],
            [ResultsPermission::Release, State::Approved, 'results_release', 'Results ready to release', '/results/release']] as [$permission, $state, $key, $title, $href]) {
            $divisions = $scope->sees($permission);
            $n = $divisions === [] ? 0 : DB::table('results_sheets')->whereIn('division_id', $divisions)->where('state', $state->value)->count();
            if ($n > 0) {
                $items[] = ['key' => $key, 'order' => 14, 'title' => $title, 'sub' => $n === 1 ? '1 course' : "{$n} courses", 'count' => (string) $n, 'tone' => 'attention', 'href' => $href];
            }
        }

        // D-106: graduation lists for the academic board, and transcripts asked for.
        $award = $scope->sees(ResultsPermission::Award);
        $n = $award === [] ? 0 : DB::table('results_award_lists')->whereIn('division_id', $award)->where('state', 'approved')->count();
        if ($n > 0) {
            $items[] = ['key' => 'results_award', 'order' => 14, 'title' => 'Graduation lists to confirm', 'sub' => $n === 1 ? '1 programme' : "{$n} programmes", 'count' => (string) $n,
                'tone' => 'attention', 'href' => '/results/graduation'];
        }
        $desk = $scope->sees(ResultsPermission::Transcripts);
        if ($desk !== []) {
            $issued = DB::table('results_transcripts')->whereNotNull('request_id')->pluck('request_id')->all();
            $n = DB::table('results_transcript_requests')->whereIn('division_id', $desk)->whereNotIn('id', $issued === [] ? ['00000000-0000-0000-0000-000000000000'] : $issued)->count();
            if ($n > 0) {
                $items[] = ['key' => 'results_transcripts', 'order' => 15, 'title' => 'Transcripts to issue', 'sub' => $n === 1 ? '1 request' : "{$n} requests", 'count' => (string) $n,
                    'tone' => 'attention', 'href' => '/results/transcripts'];
            }
        }

        return $items === [] ? [] : ['items' => $items];
    }
}
