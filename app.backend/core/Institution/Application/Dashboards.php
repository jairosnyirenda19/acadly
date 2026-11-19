<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Application;

use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\InstitutionPermission;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Boundary;
use Acadly\Kernel\Access\PlaceGroups;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * The dashboards and Today (D-100), built from what each module adds. The
 * scope follows the place the request works in: the school view covers every
 * division (the whole-school overview, for those who may see it); a division,
 * a campus or a class covers the divisions of that place.
 *
 * @phpstan-import-type Panel from DashboardPanels
 * @phpstan-import-type Now from TodayItems
 * @phpstan-import-type Item from TodayItems
 * @phpstan-import-type Chart from TodayItems
 */
final class Dashboards
{
    public function __construct(
        private readonly Access $access,
        private readonly Boundary $boundary,
        private readonly SchoolProfile $school,
        private readonly ModuleActivation $activation,
        private readonly Clock $clock,
    ) {}

    public function scope(User $viewer): DashboardScope
    {
        $all = array_map(fn (array $d): array => ['id' => $d['id'], 'name' => $d['name'], 'profile' => $d['profile']], $this->school->divisions());
        $school = $this->boundary->schoolView() && count($all) > 1;
        $ids = $school ? null : $this->divisionsOfPlace();

        if ($ids !== null) {
            $all = array_values(array_filter($all, fn (array $d): bool => in_array($d['id'], $ids, true)));
        }

        return new DashboardScope($viewer, $all, $school, $this->clock->today(), $this->access);
    }

    /**
     * The divisions of the place the request works in, or null when it is not bounded.
     *
     * @return list<string>|null
     */
    private function divisionsOfPlace(): ?array
    {
        $key = $this->boundary->key();
        if ($key === null || $key === 'institution:') {
            return null;
        }
        [$type, $id] = explode(':', $key, 2) + [1 => ''];
        $none = '00000000-0000-0000-0000-000000000000';
        $offerings = $this->boundary->offerings() ?? [];

        $found = match ($type) {
            'division' => [$id],
            'campus' => DB::table('institution_offerings')->where('campus_id', $id)->whereNull('ended_at')->pluck('division_id')->all(),
            'offering' => DB::table('institution_offerings')->where('id', $id)->pluck('division_id')->all(),
            'class' => DB::table('academic_classes')->where('id', $id)->pluck('division_id')->all(),
            default => DB::table('institution_offerings')->whereIn('id', $offerings ?: [$none])->pluck('division_id')->all(),
        };

        return array_values(array_unique(array_filter($found, 'is_string')));
    }

    /**
     * The views of the dashboard and the panels of one of them. The whole-school
     * overview is for those who may see it; inside a division, every person
     * sees what their roles show of it.
     *
     * @return array{school: bool, views: list<array{key: string, label: string}>, view: string|null, panels: list<Panel>}
     */
    public function dashboard(User $viewer, ?string $view): array
    {
        $scope = $this->scope($viewer);

        if ($scope->school) {
            $this->access->check($viewer, InstitutionPermission::Overview);
        }

        $sources = [];
        $keys = [];

        foreach (app()->tagged(DashboardPanels::TAG) as $source) {
            if (! $source instanceof DashboardPanels) {
                continue;
            }
            $own = $this->forModule($scope, $source->key());
            if ($own->divisions === []) {
                continue;
            }
            $views = $source->views($own);
            $sources[] = [$source, $own, $views];
            $keys = [...$keys, ...$views];
        }

        $views = [];
        foreach (DashboardPanels::VIEWS as $key => $label) {
            if (in_array($key, $keys, true)) {
                $views[] = ['key' => $key, 'label' => $label];
            }
        }

        $chosen = null;
        foreach ($views as $v) {
            $chosen ??= $v['key'];
            if ($v['key'] === $view) {
                $chosen = $view;
            }
        }

        $panels = [];
        foreach ($sources as [$source, $own, $has]) {
            if ($chosen !== null && in_array($chosen, $has, true)) {
                $panels = [...$panels, ...$source->panels($own, $chosen)];
            }
        }
        usort($panels, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return ['school' => $scope->school, 'views' => $views, 'view' => $chosen, 'panels' => $panels];
    }

    /**
     * The person's Today: what is happening now, one small chart, and the to-do list.
     *
     * @return array{now: list<Now>, chart: Chart|null, items: list<Item>}
     */
    public function today(User $viewer): array
    {
        $scope = $this->scope($viewer);
        $now = [];
        $chart = null;
        $items = [];

        foreach (app()->tagged(TodayItems::TAG) as $source) {
            if (! $source instanceof TodayItems) {
                continue;
            }
            // The school view holds no one's classes, lessons or rooms: only what is school-wide (approvals).
            if ($scope->school && $source->key() !== 'institution') {
                continue;
            }
            $own = $this->forModule($scope, $source->key());
            if ($own->divisions === [] && ($scope->divisions !== [] || $this->groupModules() !== null)) {
                continue;
            }
            $part = $source->today($own);
            $now = [...$now, ...($part['now'] ?? [])];
            $chart ??= $part['chart'] ?? null;
            $items = [...$items, ...($part['items'] ?? [])];
        }

        usort($now, fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $rank = fn (array $i): int => match ($i['tone']) {
            'wrong' => 0, 'attention' => 1, default => 2
        };
        usort($items, fn (array $a, array $b): int => [$rank($a), $a['order']] <=> [$rank($b), $b['order']]);

        return ['now' => array_slice($now, 0, 2), 'chart' => $chart, 'items' => $items];
    }

    /**
     * The modules open in the place, when it is a group (a finance office): only they add to its
     * dashboard and Today. Null for any other place.
     *
     * @return list<string>|null
     */
    private function groupModules(): ?array
    {
        $key = $this->boundary->key();
        if ($key === null || ! str_contains($key, ':')) {
            return null;
        }
        [$prefix, $id] = explode(':', $key, 2);
        foreach (app()->tagged(PlaceGroups::TAG) as $groups) {
            if ($groups instanceof PlaceGroups && $groups->prefix() === $prefix) {
                $group = $groups->group($id);

                return $group === null ? [] : $group['modules'];
            }
        }

        return null;
    }

    /** The scope kept to the divisions where the module is on, and to the modules a group place opens. */
    private function forModule(DashboardScope $scope, string $key): DashboardScope
    {
        $open = $this->groupModules();
        $on = $open !== null && ! in_array($key, $open, true) ? [] : array_values(array_filter($scope->divisions, fn (array $d): bool => $this->activation->isActive($key, ScopeRef::of(ScopeType::Division, $d['id']))));

        return new DashboardScope($scope->viewer, $on, $scope->school, $scope->day, $this->access);
    }
}
