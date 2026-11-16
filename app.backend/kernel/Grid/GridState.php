<?php

declare(strict_types=1);

namespace Acadly\Kernel\Grid;

/**
 * What the viewer asked of a list: the page, its size, the sort, the search
 * text and the filters. Read from the address (`?page=2&per_page=25&sort=name
 * &dir=asc&q=…&filter[class]=a,b&filter[admitted]=2025-09-01..2026-01-31`) or
 * from a saved view; anything unknown is dropped, never an error.
 */
final readonly class GridState
{
    public const array SIZES = [10, 25, 50, 100];

    /** @param array<string, list<string>> $filters */
    public function __construct(
        public int $page,
        public int $perPage,
        public string $sort,
        public string $dir,
        public string $q,
        public array $filters,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input  the query string, or a saved view's state
     * @param  list<GridFilter>  $filters
     */
    public static function from(array $input, GridDefinition $grid, array $filters): self
    {
        [$sort, $dir] = $grid->defaultSort();
        $sortable = [];

        foreach ($grid->columns() as $column) {
            if ($column->sort !== null) {
                $sortable[] = $column->key;
            }
        }

        if (is_string($input['sort'] ?? null) && in_array($input['sort'], $sortable, true)) {
            $sort = $input['sort'];
            $dir = ($input['dir'] ?? null) === 'desc' ? 'desc' : 'asc';
        }

        $size = is_numeric($input['per_page'] ?? null) ? (int) $input['per_page'] : 25;
        $page = is_numeric($input['page'] ?? null) ? max(1, (int) $input['page']) : 1;
        $q = is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 80) : '';
        $given = is_array($input['filter'] ?? null) ? $input['filter'] : (is_array($input['filters'] ?? null) ? $input['filters'] : []);
        $chosen = [];

        /** @var GridFilter $filter */
        foreach ($filters as $filter) {
            $value = $given[$filter->key] ?? null;
            $values = is_array($value) ? array_values(array_filter($value, is_string(...))) : (is_string($value) ? self::split($filter, $value) : []);

            if ($filter->kind === 'dates') {
                $values = array_map(fn (string $d): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? $d : '', array_slice([...$values, '', ''], 0, 2));

                if ($values !== ['', '']) {
                    $chosen[$filter->key] = $values;
                }

                continue;
            }

            $known = array_map(fn (GridOption $o): string => $o->value, $filter->options);
            $values = array_values(array_intersect($values, $known));

            if ($values !== []) {
                $chosen[$filter->key] = $values;
            }
        }

        return new self($page, in_array($size, self::SIZES, true) ? $size : 25, $sort, $dir, $q, $chosen);
    }

    /** @return array{page: int, per_page: int, sort: string, dir: string, q: string, filters: array<string, list<string>>} */
    public function toArray(): array
    {
        return ['page' => $this->page, 'per_page' => $this->perPage, 'sort' => $this->sort, 'dir' => $this->dir, 'q' => $this->q, 'filters' => $this->filters];
    }

    /** @return list<string> */
    private static function split(GridFilter $filter, string $value): array
    {
        return $filter->kind === 'dates' ? explode('..', $value, 2) : array_values(array_filter(explode(',', $value), fn (string $v): bool => $v !== ''));
    }
}
