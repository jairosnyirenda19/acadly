<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\Panels;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Domain\Money;
use Acadly\Modules\Fees\Domain\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Fees' part of the dashboards (D-100), for those who may see a division's
 * fees: billed against collected this term, money received by week and by
 * month, how long money has been owed, each finance office, how families pay,
 * and the classes where most is owed. Totals only: no learner is named.
 *
 * @phpstan-import-type Panel from DashboardPanels
 */
final class FeesDashboard implements DashboardPanels
{
    private const string NONE = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly TermFees $terms,
        private readonly Ledger $ledger,
        private readonly FeeOffices $offices,
    ) {}

    public function key(): string
    {
        return 'fees';
    }

    public function views(DashboardScope $scope): array
    {
        return $scope->sees(FeesPermission::View) === [] ? [] : ['overview', 'fees'];
    }

    public function panels(DashboardScope $scope, string $view): array
    {
        $seen = $scope->sees(FeesPermission::View);
        $schedules = [];
        $from = null;
        foreach ($seen as $id) {
            $term = $this->terms->currentTerm($id);
            $schedule = $term === null ? null : $this->terms->scheduleId($term['period_id'], $id);
            if ($term !== null && $schedule !== null) {
                $schedules[$id] = $schedule;
                $from = $from === null ? $term['starts_on'] : min($from, $term['starts_on']);
            }
        }
        $from ??= CarbonImmutable::parse($scope->day)->subWeeks(12)->toDateString();
        $weeks = Panels::weeks($scope->day, 12);
        $places = $this->places();
        $billed = $this->billed($schedules);
        $shares = $this->shares(min($from, $weeks[0]['from']), $scope->day, $places, $seen);
        $termShares = array_filter($shares, fn (array $s): bool => $s['day'] >= $from);
        $collected = [];
        foreach ($termShares as $s) {
            if ($s['charged']) {
                $collected[$s['division_id']] = ($collected[$s['division_id']] ?? 0) + $s['amount'];
            }
        }
        $billedAll = array_sum($billed);
        $collectedAll = array_sum($collected);
        $weekly = array_map(fn (array $w): int => array_sum(array_map(fn (array $s): int => $s['day'] >= $w['from'] && $s['day'] <= $w['to'] ? $s['amount'] : 0, $shares)), $weeks);
        $running = [];
        $sum = 0;
        foreach ($weeks as $i => $w) {
            if ($w['to'] >= $from) {
                $sum += $weekly[$i];
                $running[] = $billedAll > 0 ? round($sum / $billedAll * 100, 1) : 0;
            }
        }
        $share = Panels::percent($collectedAll, $billedAll) ?? 0;

        $meters = Panels::make('fees_by_division', $view, $view === 'overview' ? 35 : 45, 'half', 'bars', 'Fees · this term', 'Collected against billed',
            $this->behindMost($scope, $billed, $collected), ['unit' => 'money', 'rows' => array_map(fn (string $id): array => ['name' => $scope->name($id), 'division_id' => $id,
                'value' => $collected[$id] ?? 0, 'whole' => $billed[$id] ?? 0, 'note' => (Panels::percent($collected[$id] ?? 0, $billed[$id] ?? 0) ?? 0).'% · '.Money::short($collected[$id] ?? 0).' of '.Money::short($billed[$id] ?? 0), 'tone' => 'plain'],
                array_values(array_filter($seen, fn (string $id): bool => isset($billed[$id]))))]);
        $byWeek = Panels::make('fees_by_week', $view, 50, 'half', 'columns', 'Fees · by week', 'Money received each week', $this->busiest($weeks, $weekly, $collectedAll),
            ['labels' => array_column($weeks, 'label'), 'unit' => 'money', 'stacked' => false, 'highlight' => $this->largest($weekly), 'series' => [['name' => 'Received', 'division_id' => null, 'hatched' => false, 'values' => $weekly]]]);
        $kpi = Panels::kpi('fees_collected', $view, 12, 'Fees collected', $share, 'percent', Money::short($collectedAll).' of '.Money::short($billedAll), 'plain', $running ?: null);

        if ($view === 'overview') {
            return [$kpi, $meters, $byWeek];
        }
        if ($view !== 'fees') {
            return [];
        }

        $standings = $this->standings($schedules);
        $owed = 0;
        $old = 0;
        $oldLearners = 0;
        $owing = 0;
        $ages = [];
        $classes = [];
        foreach ($standings as $learner => $st) {
            $place = $places[$learner] ?? null;
            if ($place === null || ! in_array($place['division_id'], $seen, true)) {
                continue;
            }
            if ($st['balance'] > 0) {
                $owed += $st['balance'];
                $owing++;
                $classes[$place['class_id']]['owed'] = ($classes[$place['class_id']]['owed'] ?? 0) + $st['balance'];
                $classes[$place['class_id']]['learners'] = ($classes[$place['class_id']]['learners'] ?? 0) + 1;
            }
            if ($st['due_now'] > 0 && $st['overdue_since'] !== null) {
                $days = (int) CarbonImmutable::parse($st['overdue_since'])->diffInDays(CarbonImmutable::parse($scope->day));
                $bucket = $days < 30 ? 0 : ($days < 60 ? 1 : ($days < 90 ? 2 : 3));
                $ages[$place['division_id']][$bucket] = ($ages[$place['division_id']][$bucket] ?? 0) + $st['due_now'];
                if ($days >= 60) {
                    $old += $st['due_now'];
                    $oldLearners++;
                    $classes[$place['class_id']]['old'] = ($classes[$place['class_id']]['old'] ?? 0) + $st['due_now'];
                }
            }
        }
        $today = array_filter($shares, fn (array $s): bool => $s['day'] === $scope->day);

        return [
            Panels::kpi('fees_collected', $view, 11, 'Collected', $collectedAll, 'money', $share.'% of '.Money::short($billedAll).' billed', 'ink', $running ?: null),
            Panels::kpi('fees_owed', $view, 12, 'Owed', $owed, 'money', 'By '.$owing.' learners'),
            Panels::kpi('fees_old', $view, 13, 'Owed 60+ days', $old, 'money', 'By '.$oldLearners.' learners', $old > 0 ? 'attention' : 'plain'),
            Panels::kpi('fees_today', $view, 14, 'Today', array_sum(array_column($today, 'amount')), 'money', count(array_unique(array_column($today, 'payment_id'))).' payments'),
            $this->months($scope, $seen, $termShares, $from, $view),
            $this->ages($scope, $seen, $ages, $view),
            $meters,
            $this->byOffice($billed, $collected, $schedules, $termShares, $view),
            $this->methods($termShares, $view),
            $this->classes($scope, $classes, $view),
        ];
    }

    /**
     * Each learner's current place.
     *
     * @return array<string, array{division_id: string, campus_id: string, class_id: string}>
     */
    private function places(): array
    {
        $out = [];
        foreach (DB::table('placements')->whereNull('ends_on')->get(['learner_id', 'division_id', 'campus_id', 'class_id']) as $p) {
            $out[Row::text($p, 'learner_id')] = ['division_id' => Row::text($p, 'division_id'), 'campus_id' => Row::text($p, 'campus_id'), 'class_id' => Row::text($p, 'class_id')];
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $schedules  division => schedule
     * @return array<string, int> division => billed
     */
    private function billed(array $schedules): array
    {
        $out = [];
        foreach ($schedules as $division => $schedule) {
            $out[$division] = (int) DB::table('fee_charges')->where('schedule_id', $schedule)->sum('total_minor');
        }

        return $out;
    }

    /**
     * Money received, share by share, not cancelled, with the division it was for.
     *
     * @param  array<string, array{division_id: string, campus_id: string, class_id: string}>  $places
     * @param  list<string>  $seen
     * @return list<array{payment_id: string, day: string, method: string, amount: int, division_id: string, offering: string|null, charged: bool}>
     */
    private function shares(string $from, string $to, array $places, array $seen): array
    {
        $offerings = [];
        foreach (DB::table('institution_offerings')->get(['id', 'division_id', 'campus_id']) as $o) {
            $offerings[Row::text($o, 'division_id').'|'.Row::text($o, 'campus_id')] = Row::text($o, 'id');
        }
        $out = [];
        foreach (DB::table('fee_payment_shares as sh')->join('fee_payments as p', 'p.id', '=', 'sh.payment_id')->leftJoin('fee_payment_voids as v', 'v.payment_id', '=', 'p.id')
            ->leftJoin('fee_charges as c', 'c.id', '=', 'sh.charge_id')->leftJoin('fee_schedules as s', 's.id', '=', 'c.schedule_id')
            ->whereNull('v.payment_id')->whereBetween('p.received_on', [$from, $to])
            ->get(['p.id', 'p.received_on', 'p.method', 'sh.amount_minor', 'sh.learner_id', 's.division_id']) as $r) {
            $place = $places[Row::text($r, 'learner_id')] ?? null;
            $division = Row::maybeText($r, 'division_id') ?? $place['division_id'] ?? null;
            if ($division === null || ! in_array($division, $seen, true)) {
                continue;
            }
            $out[] = ['payment_id' => Row::text($r, 'id'), 'day' => Row::text($r, 'received_on'), 'method' => Row::text($r, 'method'), 'amount' => Row::int($r, 'amount_minor'),
                'division_id' => $division, 'offering' => $place === null ? null : ($offerings[$division.'|'.$place['campus_id']] ?? null), 'charged' => Row::maybeText($r, 'division_id') !== null];
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $schedules
     * @return array<string, array{instalments: list<array<string, mixed>>, charged: int, reduced: int, paid: int, balance: int, due_now: int, left: int, credit: int, overdue_since: string|null}>
     */
    private function standings(array $schedules): array
    {
        $learners = DB::table('fee_charges')->whereIn('schedule_id', array_values($schedules) ?: [self::NONE])->distinct()->pluck('learner_id')->map(fn ($v): string => is_string($v) ? $v : '')->all();

        return $this->ledger->standings(array_values($learners));
    }

    /**
     * @param  array<string, int>  $billed
     * @param  array<string, int>  $collected
     */
    private function behindMost(DashboardScope $scope, array $billed, array $collected): string
    {
        $worst = null;
        foreach ($billed as $id => $b) {
            $left = $b - ($collected[$id] ?? 0);
            if ($left > 0 && ($worst === null || $left > $worst[1])) {
                $worst = [$id, $left];
            }
        }
        if ($worst === null) {
            return $billed === [] ? 'No fees are billed for this term yet.' : 'Every division has collected what it billed.';
        }

        return count($billed) > 1 ? $scope->name($worst[0]).' has most still to collect: '.Money::short($worst[1]).'.' : Money::short($worst[1]).' still to collect.';
    }

    /**
     * @param  list<array{from: string, to: string, label: string}>  $weeks
     * @param  list<int>  $weekly
     */
    private function busiest(array $weeks, array $weekly, int $term): string
    {
        $i = $this->largest($weekly);
        if ($i === null || $weekly[$i] === 0) {
            return 'No money received in the last twelve weeks.';
        }
        $share = $term > 0 ? (int) round($weekly[$i] / $term * 100) : 0;

        return 'The week of '.$weeks[$i]['label'].' brought the most: '.Money::short($weekly[$i]).($share > 0 ? ", {$share}% of the term so far." : '.');
    }

    /** @param  list<int>  $values */
    private function largest(array $values): ?int
    {
        if ($values === [] || max($values) === 0) {
            return null;
        }

        return (int) array_search(max($values), $values, true);
    }

    /**
     * @param  list<string>  $seen
     * @param  array<int, array{payment_id: string, day: string, method: string, amount: int, division_id: string, offering: string|null, charged: bool}>  $shares
     * @return Panel
     */
    private function months(DashboardScope $scope, array $seen, array $shares, string $from, string $view): array
    {
        $months = [];
        for ($m = CarbonImmutable::parse($from)->startOfMonth(); $m->toDateString() <= $scope->day; $m = $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }
        $series = [];
        foreach ($seen as $id) {
            $values = array_map(fn (string $m): int => array_sum(array_map(fn (array $s): int => $s['division_id'] === $id && str_starts_with($s['day'], $m) ? $s['amount'] : 0, $shares)), $months);
            $series[] = ['name' => $scope->name($id), 'division_id' => $id, 'hatched' => false, 'values' => $values];
        }
        $totals = array_map(fn (int $i): int => array_sum(array_map(fn (array $s): int => (int) $s['values'][$i], $series)), array_keys($months));
        $n = count($totals);
        $finding = $n >= 2 ? CarbonImmutable::parse($months[$n - 1].'-01')->format('F').' so far: '.Money::short($totals[$n - 1]).', against '.Money::short($totals[$n - 2]).' in '.CarbonImmutable::parse($months[$n - 2].'-01')->format('F').'.' : null;

        return Panels::make('fees_by_month', $view, 30, 'half', 'columns', 'This term · by month', 'Money received, by division', $finding,
            ['labels' => array_map(fn (string $m): string => CarbonImmutable::parse($m.'-01')->format('M'), $months), 'unit' => 'money', 'stacked' => true, 'highlight' => null, 'series' => $series]);
    }

    /**
     * @param  list<string>  $seen
     * @param  array<string, array<int, int>>  $ages
     * @return Panel
     */
    private function ages(DashboardScope $scope, array $seen, array $ages, string $view): array
    {
        $series = array_map(fn (string $id): array => ['name' => $scope->name($id), 'division_id' => $id, 'hatched' => false,
            'values' => array_map(fn (int $b): int => $ages[$id][$b] ?? 0, range(0, 3))], $seen);
        $old = array_sum(array_map(fn (string $id): int => ($ages[$id][2] ?? 0) + ($ages[$id][3] ?? 0), $seen));
        $most = null;
        foreach ($seen as $id) {
            $v = ($ages[$id][2] ?? 0) + ($ages[$id][3] ?? 0);
            if ($v > 0 && ($most === null || $v > $most[1])) {
                $most = [$id, $v];
            }
        }
        $finding = $old === 0 ? 'Nothing is owed for more than 60 days.' : Money::short($old).' is owed for more than 60 days'.($most !== null && count($seen) > 1 ? '; most of it in '.$scope->name($most[0]).'.' : '.');

        return Panels::make('fees_age', $view, 40, 'half', 'columns', 'Owed · by age', 'How long money has been owed', $finding,
            ['labels' => ['Under 30 days', '30–60 days', '60–90 days', 'Over 90 days'], 'unit' => 'money', 'stacked' => true, 'highlight' => null, 'series' => $series]);
    }

    /**
     * @param  array<string, int>  $billed
     * @param  array<string, int>  $collected
     * @param  array<string, string>  $schedules
     * @param  array<int, array{payment_id: string, day: string, method: string, amount: int, division_id: string, offering: string|null, charged: bool}>  $shares
     * @return Panel
     */
    private function byOffice(array $billed, array $collected, array $schedules, array $shares, string $view): array
    {
        $bill = [];
        foreach (DB::table('fee_charges as c')->join('placements as p', fn (JoinClause $j): JoinClause => $j->on('p.learner_id', '=', 'c.learner_id')->whereNull('p.ends_on'))
            ->join('institution_offerings as o', fn (JoinClause $j): JoinClause => $j->on('o.division_id', '=', 'p.division_id')->on('o.campus_id', '=', 'p.campus_id'))
            ->whereIn('c.schedule_id', array_values($schedules) ?: [self::NONE])->groupBy('o.id')->selectRaw('o.id, sum(c.total_minor) as total')->get() as $r) {
            $office = $this->offices->ofOffering(Row::text($r, 'id'));
            $bill[$office] = ($bill[$office] ?? 0) + Row::int($r, 'total');
        }
        $got = [];
        foreach ($shares as $s) {
            if ($s['charged'] && $s['offering'] !== null) {
                $office = $this->offices->ofOffering($s['offering']);
                $got[$office] = ($got[$office] ?? 0) + $s['amount'];
            }
        }
        $rows = [];
        foreach ($this->offices->offices() as $o) {
            if (isset($bill[$o['id']])) {
                $rows[] = ['name' => $o['name'], 'division_id' => null, 'value' => $got[$o['id']] ?? 0, 'whole' => $bill[$o['id']],
                    'note' => (Panels::percent($got[$o['id']] ?? 0, $bill[$o['id']]) ?? 0).'% · '.Money::short($got[$o['id']] ?? 0).' of '.Money::short($bill[$o['id']]), 'tone' => 'plain'];
            }
        }

        return Panels::make('fees_by_office', $view, 50, 'half', 'bars', 'Finance offices · this term', 'Collected by each office', 'Each office’s share of what it billed.', ['unit' => 'money', 'rows' => $rows]);
    }

    /**
     * @param  array<int, array{payment_id: string, day: string, method: string, amount: int, division_id: string, offering: string|null, charged: bool}>  $shares
     * @return Panel
     */
    private function methods(array $shares, string $view): array
    {
        $by = [];
        foreach ($shares as $s) {
            $label = PaymentMethod::tryFrom($s['method'])?->label() ?? $s['method'];
            $by[$label] = ($by[$label] ?? 0) + $s['amount'];
        }
        arsort($by);
        $parts = [];
        foreach ($by as $name => $amount) {
            $parts[] = ['name' => (string) $name, 'value' => $amount];
        }
        $finding = $parts === [] ? 'No payments this term.' : $parts[0]['name'].' brings the most money.';

        return Panels::make('fees_methods', $view, 60, 'half', 'segments', 'Payments · this term', 'How families pay', $finding, ['unit' => 'money', 'parts' => $parts]);
    }

    /**
     * @param  array<string, array{owed?: int, learners?: int, old?: int}>  $classes
     * @return Panel
     */
    private function classes(DashboardScope $scope, array $classes, string $view): array
    {
        uasort($classes, fn (array $a, array $b): int => ($b['owed'] ?? 0) <=> ($a['owed'] ?? 0));
        $top = array_slice($classes, 0, 6, true);
        $info = [];
        foreach (DB::table('academic_classes as k')->join('institution_campuses as c', 'c.id', '=', 'k.campus_id')->whereIn('k.id', array_map('strval', array_keys($top)) ?: [self::NONE])
            ->get(['k.id', 'k.name', 'k.division_id', 'c.name as campus']) as $k) {
            $info[Row::text($k, 'id')] = $k;
        }
        $sizes = DB::table('placements')->whereIn('class_id', array_map('strval', array_keys($top)) ?: [self::NONE])->whereNull('ends_on')->groupBy('class_id')->selectRaw('class_id, count(*) as n')->pluck('n', 'class_id')->all();
        $rows = [];
        foreach ($top as $id => $c) {
            $k = $info[(string) $id] ?? null;
            if ($k === null) {
                continue;
            }
            $old = $c['old'] ?? 0;
            $rows[] = [['text' => Row::text($k, 'name'), 'tone' => null], ['text' => $scope->name(Row::text($k, 'division_id')), 'tone' => null], ['text' => Row::text($k, 'campus'), 'tone' => null],
                ['text' => ($c['learners'] ?? 0).' of '.(is_numeric($sizes[(string) $id] ?? null) ? (int) $sizes[(string) $id] : 0), 'tone' => null], ['text' => Money::text($c['owed'] ?? 0), 'tone' => null],
                ['text' => $old > 0 ? '! '.Money::text($old) : Money::text(0), 'tone' => $old > 0 ? 'attention' : null]];
        }

        return Panels::make('fees_classes', $view, 70, 'full', 'table', 'Classes · most owed', 'Where the money is owed', null,
            ['heads' => ['Class', 'Division', 'Campus', 'Learners owing', 'Owed', 'Owed 60+ days'], 'rows' => $rows]);
    }
}
