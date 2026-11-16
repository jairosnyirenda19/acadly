<?php

declare(strict_types=1);

namespace Acadly\Kernel\Grid;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Runs the lists modules declare (the approved data grid): pages, sorting,
 * search and filters on the server, each person's columns and saved views.
 *
 * @phpstan-type Page array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int, sort: string, dir: string, q: string, filters: array<string, list<string>>, columns: list<array{key: string, label: string, sortable: bool, shown: bool, always: bool}>, filter_options: list<array{key: string, label: string, kind: string, options: list<array{value: string, label: string, count: int}>}>, views: list<array{id: string, name: string, state: array<string, mixed>}>, bulk: list<array{key: string, label: string}>, can_export: bool}
 */
final class Grids
{
    public const string TAG = 'acadly.grids';

    public const int MOST_SELECTED = 5000;

    /** @param iterable<GridDefinition> $grids */
    public function __construct(private readonly iterable $grids) {}

    public function grid(string $key): GridDefinition
    {
        foreach ($this->grids as $grid) {
            if ($grid->key() === $key) {
                return $grid;
            }
        }

        throw new NotFoundHttpException;
    }

    /**
     * One page of a list.
     *
     * @param  array<array-key, mixed>  $input
     * @return Page
     */
    public function page(User $viewer, GridDefinition $grid, array $input): array
    {
        $base = $grid->query($viewer);
        $filters = $grid->filters($viewer);
        $state = GridState::from($input, $grid, $filters);
        $query = $this->narrowed(clone $base, $grid, $filters, $state);
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / $state->perPage));
        $page = min($state->page, $pages);
        $rows = array_values($this->sorted($query, $grid, $state)->forPage($page, $state->perPage)->get()->all());
        $shown = $this->shownColumns($viewer, $grid);

        return [
            'rows' => $grid->rows($viewer, $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $state->perPage,
            'pages' => $pages,
            'sort' => $state->sort,
            'dir' => $state->dir,
            'q' => $state->q,
            'filters' => $state->filters,
            'columns' => array_map(fn (GridColumn $c): array => ['key' => $c->key, 'label' => $c->label, 'sortable' => $c->sort !== null,
                'shown' => $c->always || in_array($c->key, $shown, true), 'always' => $c->always], $grid->columns()),
            'filter_options' => array_map(fn (GridFilter $f): array => [
                'key' => $f->key, 'label' => $f->label, 'kind' => $f->kind,
                'options' => array_map(fn (GridOption $o): array => ['value' => $o->value, 'label' => $o->label,
                    'count' => $this->countFor($base, $grid, $filters, $state, $f, $o)], $f->options),
            ], $filters),
            'views' => $this->views($viewer, $grid->key()),
            'bulk' => $grid->bulkActions($viewer),
            'can_export' => $grid->canExport($viewer),
        ];
    }

    /**
     * The ids of every row that matches, at most `MOST_SELECTED`: for "select all that match".
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    public function ids(User $viewer, GridDefinition $grid, array $input): array
    {
        $filters = $grid->filters($viewer);
        $state = GridState::from($input, $grid, $filters);
        $query = $this->narrowed($grid->query($viewer), $grid, $filters, $state);

        return array_values(array_filter($this->sorted($query, $grid, $state)->limit(self::MOST_SELECTED)->pluck($grid->idColumn())->all(), is_string(...)));
    }

    /**
     * Every matching row in order, a chunk at a time, for an export.
     *
     * @param  array<array-key, mixed>  $input
     * @param  list<string>|null  $only  ids the viewer selected, or null for every match
     * @param  callable(list<array<string, mixed>>): void  $each
     */
    public function each(User $viewer, GridDefinition $grid, array $input, ?array $only, callable $each): void
    {
        $filters = $grid->filters($viewer);
        $state = GridState::from($input, $grid, $filters);
        $query = $this->sorted($this->narrowed($grid->query($viewer), $grid, $filters, $state), $grid, $state);

        if ($only !== null) {
            $query->whereIn($grid->idColumn(), $only === [] ? ['00000000-0000-0000-0000-000000000000'] : $only);
        }

        for ($page = 1; ; $page++) {
            $rows = array_values((clone $query)->forPage($page, 500)->get()->all());

            if ($rows === []) {
                break;
            }

            $each($grid->rows($viewer, $rows));
        }
    }

    /** @return list<string> the columns the person chose to see, or the list's own */
    public function shownColumns(User $viewer, GridDefinition $grid): array
    {
        $saved = DB::table('grid_columns')->where('user_id', $viewer->id)->where('grid', $grid->key())->value('shown');
        $chosen = is_string($saved) ? json_decode($saved, true) : null;

        if (is_array($chosen)) {
            return array_values(array_filter($chosen, is_string(...)));
        }

        return array_values(array_map(fn (GridColumn $c): string => $c->key, array_filter($grid->columns(), fn (GridColumn $c): bool => $c->shown || $c->always)));
    }

    /** @return list<array{id: string, name: string, state: array<string, mixed>}> */
    public function views(User $viewer, string $grid): array
    {
        $out = [];

        foreach (DB::table('grid_views')->where('user_id', $viewer->id)->where('grid', $grid)->orderBy('name')->get(['id', 'name', 'state']) as $row) {
            $state = json_decode(Row::text($row, 'state'), true);
            $out[] = ['id' => Row::text($row, 'id'), 'name' => Row::text($row, 'name'), 'state' => is_array($state) ? array_filter($state, is_string(...), ARRAY_FILTER_USE_KEY) : []];
        }

        return $out;
    }

    /** @param list<GridFilter> $filters */
    private function narrowed(Builder $query, GridDefinition $grid, array $filters, GridState $state, ?GridFilter $except = null): Builder
    {
        if ($state->q !== '') {
            $grid->search($query, $state->q);
        }

        foreach ($filters as $filter) {
            if ($filter !== $except && isset($state->filters[$filter->key])) {
                $filter->apply($query, $state->filters[$filter->key]);
            }
        }

        return $query;
    }

    private function sorted(Builder $query, GridDefinition $grid, GridState $state): Builder
    {
        foreach ($grid->columns() as $column) {
            if ($column->key === $state->sort && $column->sort !== null) {
                $dir = $state->dir === 'desc' ? 'desc' : 'asc';

                // Rows without a value go last, whichever way the column is sorted.
                $query->orderByRaw("({$column->sort}) is null")->orderByRaw("{$column->sort} {$dir}");
            }
        }

        return $query->orderBy($grid->idColumn());
    }

    /**
     * How many rows an option would show, within the search and the other filters.
     *
     * @param  list<GridFilter>  $filters
     */
    private function countFor(Builder $base, GridDefinition $grid, array $filters, GridState $state, GridFilter $filter, GridOption $option): int
    {
        $query = $this->narrowed(clone $base, $grid, $filters, $state, $filter);
        ($option->apply)($query);

        return $query->count();
    }
}
