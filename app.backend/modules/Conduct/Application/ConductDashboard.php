<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Application;

use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\Panels;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Conduct\Contracts\ConductPermission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Conduct's part of the dashboards and of Today (D-100): commendations and
 * incidents this term and by week, incidents for every hundred learners, the
 * serious incidents still open; on Today, the open serious incidents the
 * person leads. Counts only: no learner is named.
 *
 * @phpstan-import-type Panel from DashboardPanels
 * @phpstan-import-type Part from TodayItems
 */
final class ConductDashboard implements DashboardPanels
{
    public function __construct(private readonly Teaching $teaching) {}

    public function key(): string
    {
        return 'conduct';
    }

    public function views(DashboardScope $scope): array
    {
        return $scope->sees(ConductPermission::View) === [] ? [] : ['conduct'];
    }

    public function panels(DashboardScope $scope, string $view): array
    {
        if ($view !== 'conduct') {
            return [];
        }

        $seen = $scope->sees(ConductPermission::View);
        $weeks = Panels::weeks($scope->day, 12);
        $from = $this->termStart($seen, $scope->day);
        $entries = $this->entries($seen, min($from, $weeks[0]['from']), $scope->day);
        $term = array_filter($entries, fn (array $e): bool => $e['day'] >= $from);
        $good = count(array_filter($term, fn (array $e): bool => $e['commendation']));
        $bad = count($term) - $good;
        $weekly = fn (bool $commendation): array => array_map(fn (array $w): int => count(array_filter($entries, fn (array $e): bool => $e['commendation'] === $commendation && $e['day'] >= $w['from'] && $e['day'] <= $w['to'])), $weeks);
        $open = DB::table('conduct_cases as k')->join('academic_classes as c', 'c.id', '=', 'k.class_id')->whereIn('c.division_id', $seen ?: [self::NONE])
            ->whereNotIn('k.state', ['closed', 'withdrawn'])->groupBy('c.division_id')->selectRaw('c.division_id, count(*) as n')->pluck('n', 'c.division_id')->all();
        $openCount = array_sum(array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $open));
        $openText = [];
        foreach ($open as $d => $n) {
            $openText[] = $scope->name((string) $d).' '.(is_numeric($n) ? (int) $n : 0);
        }
        $c = $weekly(true);
        $i = $weekly(false);
        $ratio = array_sum($i) > 0 ? round(array_sum($c) / array_sum($i), 1) : null;

        return [
            Panels::kpi('commendations', $view, 11, 'Commendations', $good, 'count', 'This term', 'ink', array_slice($c, 6)),
            Panels::kpi('incidents', $view, 12, 'Incidents', $bad, 'count', 'This term', 'plain', array_slice($i, 6)),
            Panels::kpi('serious_open', $view, 13, 'Serious, open', $openCount, 'count', $openCount === 0 ? 'None open' : implode(' · ', $openText), $openCount > 0 ? 'attention' : 'plain'),
            Panels::make('conduct_weeks', $view, 30, 'half', 'line', '12 weeks', 'Commendations and incidents',
                $ratio === null ? 'No incidents in twelve weeks.' : "There were {$ratio} commendations for every incident.",
                ['labels' => array_column($weeks, 'label'), 'unit' => 'count', 'min' => 0, 'max' => max(5, ...$c, ...$i), 'series' => [
                    ['name' => 'Commendations', 'division_id' => null, 'dashed' => false, 'values' => $c],
                    ['name' => 'Incidents', 'division_id' => null, 'dashed' => true, 'values' => $i],
                ]]),
            $this->perHundred($scope, $seen, $term, $view),
        ];
    }

    /** @return Part */
    public function today(DashboardScope $scope): array
    {
        $cases = DB::table('conduct_cases as k')->join('academic_classes as c', 'c.id', '=', 'k.class_id')->whereIn('c.division_id', $scope->ids() ?: [self::NONE])
            ->where('k.lead_by', $scope->viewer->id)->whereNotIn('k.state', ['closed', 'withdrawn'])->orderBy('k.created_at')->get(['k.id', 'c.name']);
        if ($cases->isEmpty()) {
            return [];
        }
        $first = $cases->first();

        return ['items' => [['key' => 'serious_cases', 'order' => 8, 'title' => 'Serious incident', 'sub' => Row::text($first, 'name').' · open, you lead it',
            'count' => '! '.$cases->count(), 'tone' => 'wrong', 'href' => $cases->count() === 1 ? '/conduct/incidents/'.Row::text($first, 'id') : '/conduct']]];
    }

    private const string NONE = '00000000-0000-0000-0000-000000000000';

    /**
     * Each entry once per learner, not withdrawn, with the class's division.
     *
     * @param  list<string>  $seen
     * @return list<array{day: string, commendation: bool, division_id: string, class_id: string}>
     */
    private function entries(array $seen, string $from, string $to): array
    {
        $out = [];
        foreach (DB::table('conduct_entries as e')->join('conduct_entry_learners as l', 'l.entry_id', '=', 'e.id')->join('academic_classes as c', 'c.id', '=', 'l.class_id')
            ->leftJoin('conduct_withdrawals as w', 'w.entry_id', '=', 'e.id')->whereNull('w.entry_id')->whereIn('c.division_id', $seen ?: [self::NONE])
            ->whereBetween('e.happened_on', [$from, $to])->get(['e.happened_on', 'e.commendation', 'c.division_id', 'c.id']) as $r) {
            $out[] = ['day' => substr(Row::text($r, 'happened_on'), 0, 10), 'commendation' => (bool) $r->commendation, 'division_id' => Row::text($r, 'division_id'), 'class_id' => Row::text($r, 'id')];
        }

        return $out;
    }

    /**
     * @param  list<string>  $seen
     * @param  array<int, array{day: string, commendation: bool, division_id: string, class_id: string}>  $term
     * @return Panel
     */
    private function perHundred(DashboardScope $scope, array $seen, array $term, string $view): array
    {
        $key = $scope->school ? 'division_id' : 'class_id';
        $sizes = DB::table('placements')->whereIn('division_id', $seen ?: [self::NONE])->whereNull('ends_on')->groupBy($key)->selectRaw("{$key} as k, count(*) as n")->pluck('n', 'k')->all();
        $names = $scope->school ? [] : DB::table('academic_classes')->whereIn('id', array_map('strval', array_keys($sizes)) ?: [self::NONE])->pluck('name', 'id')->all();
        $rows = [];
        foreach ($sizes as $k => $n) {
            $count = count(array_filter($term, fn (array $e): bool => ! $e['commendation'] && $e[$key] === (string) $k));
            $size = is_numeric($n) ? (int) $n : 0;
            // Inside a division, only the classes that had an incident; every division shows in the school view.
            if ($size === 0 || (! $scope->school && $count === 0)) {
                continue;
            }
            $per = round($count / $size * 100, 1);
            $name = $scope->school ? $scope->name((string) $k) : (is_string($names[$k] ?? null) ? $names[$k] : '');
            $rows[] = ['name' => $name, 'division_id' => $scope->school ? (string) $k : null, 'value' => $per, 'whole' => null, 'note' => $per.' per 100', 'tone' => 'plain'];
        }
        usort($rows, fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        $finding = $rows === [] || $rows[0]['value'] === 0.0 ? 'No incidents this term.' : $rows[0]['name'].' has the most for each learner.';

        return Panels::make('conduct_per_hundred', $view, 40, 'half', 'bars', 'This term · for every 100 learners', $scope->school ? 'Incidents by division' : 'Incidents by class', $finding, ['unit' => 'count', 'rows' => $rows]);
    }

    /** @param  list<string>  $seen */
    private function termStart(array $seen, string $day): string
    {
        $starts = [];
        foreach ($seen as $id) {
            $p = $this->teaching->periodAt($id, $day);
            if ($p !== null) {
                $starts[] = $p['starts_on'];
            }
        }

        return $starts === [] ? CarbonImmutable::parse($day)->subWeeks(12)->toDateString() : min($starts);
    }
}
