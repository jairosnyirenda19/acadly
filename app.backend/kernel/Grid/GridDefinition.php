<?php

declare(strict_types=1);

namespace Acadly\Kernel\Grid;

use Acadly\Kernel\Identity\User;
use Illuminate\Database\Query\Builder;

/**
 * A list that can grow to hundreds of rows (the approved data grid): the
 * module that owns the records declares it and tags it `acadly.grids`.
 * `Grids` pages, sorts, searches and filters it on the server, keeps each
 * person's columns and views, exports it in the background and runs its bulk
 * actions. The kernel names no module.
 */
interface GridDefinition
{
    /** The list's key: 'people'. */
    public function key(): string;

    /** The list's name, for an export file: 'People'. */
    public function title(): string;

    /**
     * The rows the viewer may see, before search, filters and sort. Refuses
     * through `Access::check` a viewer who may see none.
     */
    public function query(User $viewer): Builder;

    /** The SQL of a row's id in the query: 'l.id'. */
    public function idColumn(): string;

    /** @return list<GridColumn> in order */
    public function columns(): array;

    /** @return list<GridFilter> */
    public function filters(User $viewer): array;

    /** Narrows the query to rows that match the text the viewer typed. */
    public function search(Builder $query, string $text): void;

    /** @return array{0: string, 1: 'asc'|'desc'} a column key and direction */
    public function defaultSort(): array;

    /**
     * The page's rows as the screen shows them.
     *
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    public function rows(User $viewer, array $rows): array;

    /**
     * A cell as plain text or a number, for an export.
     *
     * @param  array<string, mixed>  $row  one of `rows()`
     */
    public function plain(array $row, string $column): string|int|float|null;

    /** Whether the viewer may export the list. */
    public function canExport(User $viewer): bool;

    /** @return list<array{key: string, label: string}> the bulk actions the viewer may take */
    public function bulkActions(User $viewer): array;

    /**
     * Takes a bulk action on rows, one by one, through the module's Actions.
     *
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $input
     */
    public function bulk(User $viewer, string $action, array $ids, array $input): BulkResult;
}
