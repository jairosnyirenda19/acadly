<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\DivisionFigures;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\Panels;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Enrolment's part of the dashboards (D-100): how many learners each division
 * has, how the school has grown, who joined and who left this term, and the
 * items each division has waiting (from the Divisions start screen figures).
 * Counts only: no learner is named.
 *
 * @phpstan-import-type Panel from DashboardPanels
 */
final class EnrolmentDashboard implements DashboardPanels
{
    public function __construct(
        private readonly Teaching $teaching,
        private readonly ModuleActivation $activation,
    ) {}

    public function key(): string
    {
        return 'enrolment';
    }

    public function views(DashboardScope $scope): array
    {
        return $scope->sees(EnrolmentPermission::View) === [] ? [] : ['overview', 'enrolment'];
    }

    public function panels(DashboardScope $scope, string $view): array
    {
        $seen = $scope->sees(EnrolmentPermission::View);
        $now = $this->countOn($seen, $scope->day);
        $total = array_sum($now);
        $trend = [];
        for ($m = 5; $m >= 0; $m--) {
            $trend[] = array_sum($this->countOn($seen, CarbonImmutable::parse($scope->day)->subMonthsNoOverflow($m)->startOfMonth()->toDateString()));
        }
        array_pop($trend);
        $trend[] = $total;
        $byDivision = Panels::make('learners_by_division', $view, $view === 'overview' ? 40 : 60, 'half', 'bars', 'Learners', 'Learners by division',
            $this->largest($scope, $now, $total), ['unit' => 'count', 'rows' => $this->rows($scope, $seen, $now)]);

        if ($view === 'overview') {
            // Inside one division the split by division says nothing.
            $out = [Panels::kpi('learners', $view, 10, 'Learners', $total, 'count', $this->since($trend), 'ink', $trend), ...(count($seen) > 1 ? [$byDivision] : [])];

            if ($scope->school) {
                [$count, $rows] = $this->attention($scope, $seen);
                $out[] = Panels::kpi('attention', $view, 13, 'Needs attention', $count, 'count', $count === 0 ? 'Nothing is waiting.' : 'In '.count(array_unique(array_column($rows, 'division_id'))).' divisions', $count > 0 ? 'attention' : 'plain');
                $out[] = Panels::make('attention_list', $view, 70, 'full', 'list', 'Needs attention', $count === 0 ? 'Nothing is waiting' : "{$count} items, by division", null, ['rows' => $rows]);
            }

            return $out;
        }

        if ($view !== 'enrolment') {
            return [];
        }

        [$from, $joined, $left] = $this->movement($scope, $seen);

        return [
            Panels::kpi('learners', $view, 10, 'Learners', $total, 'count', $this->since($trend), 'ink', $trend),
            Panels::kpi('joined', $view, 11, 'Joined this term', array_sum(array_map('array_sum', $joined)), 'count', 'Since '.CarbonImmutable::parse($from)->format('j F')),
            Panels::kpi('left', $view, 12, 'Left this term', array_sum(array_map('array_sum', $left)), 'count', 'Since '.CarbonImmutable::parse($from)->format('j F')),
            Panels::kpi('campuses', $view, 13, 'Campuses', DB::table('institution_offerings')->whereIn('division_id', $seen ?: ['00000000-0000-0000-0000-000000000000'])->whereNull('ended_at')->distinct()->count('campus_id'), 'count', count($seen).' divisions'),
            $this->years($scope, $seen, $view),
            $this->movementPanel($view, $joined, $left),
            ...(count($seen) > 1 ? [$byDivision] : []),
            Panels::make('learners_by_campus', $view, 70, 'half', 'bars', 'Learners · now', 'Learners by campus', null, ['unit' => 'count', 'rows' => $this->campuses($seen)]),
        ];
    }

    /**
     * Learners placed on a day, by division.
     *
     * @param  list<string>  $seen
     * @return array<string, int>
     */
    private function countOn(array $seen, string $day): array
    {
        $out = array_fill_keys($seen, 0);
        foreach (DB::table('placements')->whereIn('division_id', $seen ?: ['00000000-0000-0000-0000-000000000000'])->where('starts_on', '<=', $day)
            ->where(fn (Builder $q): Builder => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $day))
            ->groupBy('division_id')->selectRaw('division_id, count(distinct learner_id) as n')->get() as $r) {
            $out[Row::text($r, 'division_id')] = Row::int($r, 'n');
        }

        return $out;
    }

    /**
     * @param  list<string>  $seen
     * @param  array<string, int>  $now
     * @return list<array{name: string, division_id: string, value: int, whole: null, note: null, tone: 'plain'}>
     */
    private function rows(DashboardScope $scope, array $seen, array $now): array
    {
        $rows = [];
        foreach ($seen as $id) {
            $rows[] = ['name' => $scope->name($id), 'division_id' => $id, 'value' => $now[$id] ?? 0, 'whole' => null, 'note' => null, 'tone' => 'plain'];
        }
        usort($rows, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $rows;
    }

    /** @param  array<string, int>  $now */
    private function largest(DashboardScope $scope, array $now, int $total): ?string
    {
        if ($total === 0 || count($now) < 2) {
            return null;
        }
        arsort($now);
        $id = (string) array_key_first($now);
        $share = (int) round(($now[$id] / $total) * 100);

        return "{$scope->name($id)} holds {$share}% of the learners.";
    }

    /** @param  list<int>  $trend */
    private function since(array $trend): string
    {
        $change = $trend[5] - $trend[0];

        return match (true) {
            $change > 0 => "+{$change} in six months",
            $change < 0 => "{$change} in six months",
            default => 'No change in six months',
        };
    }

    /**
     * The items waiting in each division, from the start screen figures.
     *
     * @param  list<string>  $seen
     * @return array{0: int, 1: list<array{division_id: string, text: string, tone: string, href: null}>}
     */
    private function attention(DashboardScope $scope, array $seen): array
    {
        $rows = [];
        $count = 0;
        foreach (app()->tagged(DivisionFigures::TAG) as $source) {
            if (! $source instanceof DivisionFigures || ! $this->activation->isActive($source->key())) {
                continue;
            }
            foreach ($source->figures($scope->viewer, $scope->ids(), $scope->day) as $id => $f) {
                $state = $f['state'] ?? null;
                if ($state !== null && $state['tone'] !== 'done') {
                    $rows[] = ['division_id' => $id, 'text' => $state['text'], 'tone' => $state['tone'], 'href' => null];
                    $count += max(1, (int) $state['text']);
                }
            }
        }
        $order = array_flip($scope->ids());
        usort($rows, fn (array $a, array $b): int => ($order[$a['division_id']] ?? 0) <=> ($order[$b['division_id']] ?? 0));

        return [$count, $rows];
    }

    /**
     * Learners at the start of each of the last five school years, by division.
     *
     * @param  list<string>  $seen
     * @return Panel
     */
    private function years(DashboardScope $scope, array $seen, string $view): array
    {
        $years = DB::table('academic_years')->where('starts_on', '<=', $scope->day)->orderByDesc('starts_on')->limit(5)->get(['name', 'starts_on'])->reverse()->values();
        $labels = [];
        $series = [];
        foreach ($seen as $id) {
            $series[$id] = ['name' => $scope->name($id), 'division_id' => $id, 'hatched' => false, 'values' => []];
        }
        foreach ($years as $y) {
            $labels[] = Row::text($y, 'name');
            $on = $this->countOn($seen, max(Row::text($y, 'starts_on'), CarbonImmutable::parse(Row::text($y, 'starts_on'))->addDays(14)->min(CarbonImmutable::parse($scope->day))->toDateString()));
            foreach ($seen as $id) {
                $series[$id]['values'][] = $on[$id] ?? 0;
            }
        }
        $first = $series === [] || $labels === [] ? 0 : array_sum(array_map(fn (array $s): int => (int) $s['values'][0], $series));
        $last = $series === [] || $labels === [] ? 0 : array_sum(array_map(fn (array $s): int => (int) end($s['values']), $series));
        $finding = count($labels) > 1 && $first > 0 ? 'The school has grown by '.(int) round(($last - $first) / $first * 100).'% since '.$labels[0].'.' : null;

        return Panels::make('learners_by_year', $view, 40, 'half', 'columns', count($labels).' years · start of the year', 'Learners by division', $finding,
            ['labels' => $labels, 'unit' => 'count', 'stacked' => true, 'highlight' => null, 'series' => array_values($series)]);
    }

    /**
     * Who joined and who left in each month of the term so far.
     *
     * @param  list<string>  $seen
     * @return array{0: string, 1: array<string, array<string, int>>, 2: array<string, array<string, int>>}
     */
    private function movement(DashboardScope $scope, array $seen): array
    {
        $starts = [];
        foreach ($seen as $id) {
            $p = $this->teaching->periodAt($id, $scope->day);
            if ($p !== null) {
                $starts[] = $p['starts_on'];
            }
        }
        $from = $starts === [] ? CarbonImmutable::parse($scope->day)->startOfMonth()->toDateString() : min($starts);
        $joined = [];
        $left = [];
        foreach (DB::table('placements')->whereIn('division_id', $seen ?: ['00000000-0000-0000-0000-000000000000'])->where('entry_reason', 'admission')->whereBetween('starts_on', [$from, $scope->day])->get(['division_id', 'starts_on']) as $r) {
            $month = substr(Row::text($r, 'starts_on'), 0, 7);
            $joined[$month][Row::text($r, 'division_id')] = ($joined[$month][Row::text($r, 'division_id')] ?? 0) + 1;
        }
        foreach (DB::table('enrolments as e')->whereBetween('e.ended_on', [$from, $scope->day])
            ->selectRaw('e.ended_on, (select p.division_id from placements p where p.enrolment_id = e.id order by p.starts_on desc limit 1) as division_id')->get() as $r) {
            $division = Row::text($r, 'division_id');
            if (in_array($division, $seen, true)) {
                $month = substr(Row::text($r, 'ended_on'), 0, 7);
                $left[$month][$division] = ($left[$month][$division] ?? 0) + 1;
            }
        }

        return [$from, $joined, $left];
    }

    /**
     * @param  array<string, array<string, int>>  $joined
     * @param  array<string, array<string, int>>  $left
     * @return Panel
     */
    private function movementPanel(string $view, array $joined, array $left): array
    {
        $months = array_values(array_unique([...array_keys($joined), ...array_keys($left)]));
        sort($months);
        $labels = array_map(fn (string $m): string => CarbonImmutable::parse($m.'-01')->format('M'), $months);
        $in = array_map(fn (string $m): int => array_sum($joined[$m] ?? []), $months);
        $out = array_map(fn (string $m): int => array_sum($left[$m] ?? []), $months);
        $more = count(array_filter(array_keys($months), fn (int $i): bool => $in[$i] > $out[$i]));
        $finding = $months === [] ? 'No one has joined or left this term.' : ($more === count($months) ? 'More joined than left in every month.' : "More joined than left in {$more} of ".count($months).' months.');

        return Panels::make('joined_left', $view, 50, 'half', 'columns', 'This term · by month', 'Joiners and leavers', $finding, ['labels' => $labels, 'unit' => 'count', 'stacked' => false, 'highlight' => null,
            'series' => [['name' => 'Joined', 'division_id' => null, 'hatched' => false, 'values' => $in], ['name' => 'Left', 'division_id' => null, 'hatched' => true, 'values' => $out]]]);
    }

    /**
     * @param  list<string>  $seen
     * @return list<array{name: string, division_id: null, value: int, whole: null, note: null, tone: 'plain'}>
     */
    private function campuses(array $seen): array
    {
        $rows = [];
        foreach (DB::table('placements as p')->join('institution_campuses as c', 'c.id', '=', 'p.campus_id')->whereIn('p.division_id', $seen ?: ['00000000-0000-0000-0000-000000000000'])->whereNull('p.ends_on')
            ->groupBy('c.name', 'c.position')->orderBy('c.position')->selectRaw('c.name, count(distinct p.learner_id) as n')->get() as $r) {
            $rows[] = ['name' => Row::text($r, 'name'), 'division_id' => null, 'value' => Row::int($r, 'n'), 'whole' => null, 'note' => null, 'tone' => 'plain'];
        }

        return $rows;
    }
}
