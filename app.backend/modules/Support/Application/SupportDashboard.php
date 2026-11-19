<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Application;

use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\Panels;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Support\Contracts\SupportPermission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Support's part of the dashboards and of Today (D-100): the plans open and
 * the reviews due, by division (by class inside a division); on Today, the
 * reviews of the plans the person leads. Counts only: no learner is named,
 * and a confidential plan is counted like any other.
 *
 * @phpstan-import-type Part from TodayItems
 */
final class SupportDashboard implements DashboardPanels
{
    private const string NONE = '00000000-0000-0000-0000-000000000000';

    public function key(): string
    {
        return 'support';
    }

    public function views(DashboardScope $scope): array
    {
        return $scope->sees(SupportPermission::View) === [] ? [] : ['conduct'];
    }

    public function panels(DashboardScope $scope, string $view): array
    {
        if ($view !== 'conduct') {
            return [];
        }

        $seen = $scope->sees(SupportPermission::View);
        $end = CarbonImmutable::parse($scope->day)->endOfMonth()->toDateString();
        $plans = DB::table('support_plans as s')->join('academic_classes as c', 'c.id', '=', 's.class_id')->whereIn('c.division_id', $seen ?: [self::NONE])
            ->whereIn('s.state', ['raised', 'reviewing'])->get(['s.next_review_on', 'c.division_id', 'c.id', 'c.name']);
        $due = $plans->filter(fn (object $p): bool => Row::maybeText($p, 'next_review_on') !== null && Row::text($p, 'next_review_on') <= $end)->count();
        $groups = [];
        foreach ($plans as $p) {
            $k = $scope->school ? Row::text($p, 'division_id') : Row::text($p, 'id');
            $groups[$k] ??= ['name' => $scope->school ? $scope->name($k) : Row::text($p, 'name'), 'division_id' => $scope->school ? $k : null, 'value' => 0, 'whole' => null, 'note' => null, 'tone' => 'plain'];
            $groups[$k]['value']++;
        }
        $rows = array_values($groups);
        usort($rows, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return [
            Panels::kpi('plans_open', $view, 14, 'Support plans', $plans->count(), 'count', $due === 0 ? 'None to review this month' : "{$due} to review this month"),
            Panels::make('plans_by_place', $view, 50, 'half', 'bars', 'Support plans · open', $scope->school ? 'Plans by division' : 'Plans by class',
                $rows === [] ? 'No support plan is open.' : $rows[0]['name'].' has the most open plans.', ['unit' => 'count', 'rows' => $rows]),
        ];
    }

    /** @return Part */
    public function today(DashboardScope $scope): array
    {
        $soon = CarbonImmutable::parse($scope->day)->addDays(7)->toDateString();
        $plans = DB::table('support_plans as s')->join('academic_classes as c', 'c.id', '=', 's.class_id')->whereIn('c.division_id', $scope->ids() ?: [self::NONE])
            ->where('s.lead_by', $scope->viewer->id)->whereIn('s.state', ['raised', 'reviewing'])->whereNotNull('s.next_review_on')->where('s.next_review_on', '<=', $soon)
            ->orderBy('s.next_review_on')->get(['s.id', 's.next_review_on']);
        if ($plans->isEmpty()) {
            return [];
        }
        $first = $plans->first();
        $late = Row::text($first, 'next_review_on') < $scope->day;

        return ['items' => [['key' => 'plan_reviews', 'order' => 40, 'title' => 'Support plan reviews', 'sub' => 'Next due '.CarbonImmutable::parse(Row::text($first, 'next_review_on'))->format('j M'),
            'count' => (string) $plans->count(), 'tone' => $late ? 'attention' : 'plain', 'href' => $plans->count() === 1 ? '/support/plans/'.Row::text($first, 'id') : '/support']]];
    }
}
