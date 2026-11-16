<?php

declare(strict_types=1);

namespace Acadly\Kernel\Grid;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The writers of the data grid: a person's views and columns, an export asked
 * for, and a bulk action. Every one first asks the list for the rows the
 * person may see, so a person who may see none is refused.
 */
final class GridActions
{
    public const int MOST_VIEWS = 20;

    public const int KEPT_DAYS = 7;

    public function __construct(
        private readonly Grids $grids,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * Saves a named view, or changes one.
     *
     * @param  array<array-key, mixed>  $state
     * @return string the view's id
     */
    public function saveView(User $by, GridDefinition $grid, ?string $id, string $name, array $state): string
    {
        $grid->query($by);
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 60) {
            throw ValidationException::withMessages(['name' => ['Name the view in up to 60 characters.']]);
        }

        $clean = GridState::from($state, $grid, $grid->filters($by))->toArray();
        $columns = is_array($state['columns'] ?? null) ? array_values(array_filter($state['columns'], is_string(...))) : null;
        $kept = ['sort' => $clean['sort'], 'dir' => $clean['dir'], 'q' => $clean['q'], 'filters' => $clean['filters'], 'per_page' => $clean['per_page'],
            ...($columns !== null ? ['columns' => $columns] : [])];
        $same = DB::table('grid_views')->where('user_id', $by->id)->where('grid', $grid->key())->where('name', $name)->value('id');

        if (is_string($same) && $same !== $id) {
            throw ValidationException::withMessages(['name' => ['You already have a view with this name.']]);
        }

        if ($id !== null) {
            $updated = DB::table('grid_views')->where('id', $id)->where('user_id', $by->id)->where('grid', $grid->key())
                ->update(['name' => $name, 'state' => json_encode($kept), 'updated_at' => $this->clock->now()]);

            return $updated === 1 ? $id : throw new NotFoundHttpException;
        }

        if (DB::table('grid_views')->where('user_id', $by->id)->where('grid', $grid->key())->count() >= self::MOST_VIEWS) {
            throw ValidationException::withMessages(['name' => ['You have '.self::MOST_VIEWS.' views of this list. Delete one first.']]);
        }

        $id = Ids::next();
        DB::table('grid_views')->insert(['id' => $id, 'user_id' => $by->id, 'grid' => $grid->key(), 'name' => $name, 'state' => json_encode($kept),
            'created_at' => $this->clock->now(), 'updated_at' => $this->clock->now()]);

        return $id;
    }

    public function deleteView(User $by, GridDefinition $grid, string $id): void
    {
        $grid->query($by);

        if (DB::table('grid_views')->where('id', $id)->where('user_id', $by->id)->where('grid', $grid->key())->delete() !== 1) {
            throw new NotFoundHttpException;
        }
    }

    /** @param list<string> $shown */
    public function saveColumns(User $by, GridDefinition $grid, array $shown): void
    {
        $grid->query($by);
        $keys = [];

        foreach ($grid->columns() as $column) {
            if ($column->always || in_array($column->key, $shown, true)) {
                $keys[] = $column->key;
            }
        }

        DB::table('grid_columns')->updateOrInsert(['user_id' => $by->id, 'grid' => $grid->key()], ['shown' => json_encode($keys), 'updated_at' => $this->clock->now()]);
    }

    /** Shows the list's own columns again. */
    public function resetColumns(User $by, GridDefinition $grid): void
    {
        $grid->query($by);
        DB::table('grid_columns')->where('user_id', $by->id)->where('grid', $grid->key())->delete();
    }

    /**
     * Asks for an export of the rows that match (or of the ones selected), built on the queue after commit.
     *
     * @param  array<array-key, mixed>  $state
     * @param  list<string>|null  $only
     */
    public function export(User $by, GridDefinition $grid, array $state, ?array $only): string
    {
        $grid->query($by);

        if (! $grid->canExport($by)) {
            throw new AuthorizationException;
        }

        $clean = GridState::from($state, $grid, $grid->filters($by))->toArray();
        $columns = $this->grids->shownColumns($by, $grid);
        $id = Ids::next();

        DB::transaction(function () use ($by, $grid, $clean, $columns, $only, $id): void {
            DB::table('grid_exports')->insert(['id' => $id, 'user_id' => $by->id, 'grid' => $grid->key(), 'state' => json_encode($clean),
                'columns' => json_encode($columns), 'only' => $only !== null ? json_encode(array_slice($only, 0, Grids::MOST_SELECTED)) : null,
                'status' => 'queued', 'requested_at' => $this->clock->now()]);
            $this->audit->record(KernelAudit::ExportRequested, actor: $this->audit->actorFor($by), meta: ['grid' => $grid->key(), 'export' => $id]);
            BuildExport::dispatch($id)->afterCommit();
        });

        return $id;
    }

    /**
     * Takes a bulk action on the rows chosen: the ids given, or every row that matches.
     *
     * @param  list<string>|null  $ids
     * @param  array<array-key, mixed>  $state
     * @param  array<string, mixed>  $input
     */
    public function bulk(User $by, GridDefinition $grid, string $action, ?array $ids, array $state, array $input): BulkResult
    {
        $grid->query($by);

        if (! in_array($action, array_column($grid->bulkActions($by), 'key'), true)) {
            throw new AuthorizationException;
        }

        $visible = $this->grids->ids($by, $grid, $state);
        $chosen = $ids === null ? $visible : array_values(array_intersect($ids, $visible));

        if ($chosen === []) {
            throw ValidationException::withMessages(['ids' => ['Choose at least one row.']]);
        }

        return $grid->bulk($by, $action, $chosen, $input);
    }

    /** @return object the export row, when it is the person's own */
    public function ownExport(User $by, string $id): object
    {
        $row = DB::table('grid_exports')->where('id', $id)->where('user_id', $by->id)->first();

        return $row ?? throw new NotFoundHttpException;
    }

    /** Records the download of an export, and gives back its file path. */
    public function download(User $by, string $id): string
    {
        $row = $this->ownExport($by, $id);

        if (Row::text($row, 'status') !== 'ready' || Row::maybeText($row, 'path') === null) {
            throw new NotFoundHttpException;
        }

        $this->audit->record(KernelAudit::ExportDownloaded, actor: $this->audit->actorFor($by), meta: ['grid' => Row::text($row, 'grid'), 'export' => $id, 'rows' => Row::int($row, 'rows')]);

        return Row::text($row, 'path');
    }
}
