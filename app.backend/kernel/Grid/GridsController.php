<?php

declare(strict_types=1);

namespace Acadly\Kernel\Grid;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What every data grid shares (the approved design): a person's views and
 * columns, exports and bulk actions. Each list's rows come from the module
 * that owns them, through its own address.
 */
final class GridsController
{
    public function __construct(
        private readonly Grids $grids,
        private readonly GridActions $actions,
    ) {}

    /**
     * Saves a view: `{name, state}`.
     *
     * @response array{id: string, views: list<array{id: string, name: string, state: array<string, mixed>}>}
     */
    public function storeView(Request $request, string $grid): JsonResponse
    {
        $viewer = self::user($request);
        $definition = $this->grids->grid($grid);
        $state = $request->input('state');
        $id = $this->actions->saveView($viewer, $definition, null, $request->string('name')->toString(), is_array($state) ? $state : []);

        return response()->json(['id' => $id, 'views' => $this->grids->views($viewer, $grid)], 201);
    }

    /**
     * Renames a view or keeps the list as it is now in it: `{name, state}`.
     *
     * @response array{id: string, views: list<array{id: string, name: string, state: array<string, mixed>}>}
     */
    public function updateView(Request $request, string $grid, string $id): JsonResponse
    {
        $viewer = self::user($request);
        $state = $request->input('state');
        $this->actions->saveView($viewer, $this->grids->grid($grid), $id, $request->string('name')->toString(), is_array($state) ? $state : []);

        return response()->json(['id' => $id, 'views' => $this->grids->views($viewer, $grid)]);
    }

    /** @response array{views: list<array{id: string, name: string, state: array<string, mixed>}>} */
    public function deleteView(Request $request, string $grid, string $id): JsonResponse
    {
        $viewer = self::user($request);
        $this->actions->deleteView($viewer, $this->grids->grid($grid), $id);

        return response()->json(['views' => $this->grids->views($viewer, $grid)]);
    }

    /**
     * Keeps the columns a person chose: `{shown: [key, …]}`, or `{reset: true}` for the list's own.
     *
     * @response array{shown: list<string>}
     */
    public function columns(Request $request, string $grid): JsonResponse
    {
        $viewer = self::user($request);
        $definition = $this->grids->grid($grid);
        $shown = $request->input('shown');

        if ($request->boolean('reset')) {
            $this->actions->resetColumns($viewer, $definition);
        } else {
            $this->actions->saveColumns($viewer, $definition, is_array($shown) ? array_values(array_filter($shown, is_string(...))) : []);
        }

        return response()->json(['shown' => $this->grids->shownColumns($viewer, $definition)]);
    }

    /**
     * Asks for an export, built in the background: `{state, ids?}`. Without ids, every row that matches.
     *
     * @response array{id: string, status: 'queued'|'building'|'ready'|'failed'|'expired'}
     */
    public function export(Request $request, string $grid): JsonResponse
    {
        $viewer = self::user($request);
        $state = $request->input('state');
        $ids = $request->input('ids');
        $id = $this->actions->export($viewer, $this->grids->grid($grid), is_array($state) ? $state : [],
            is_array($ids) ? array_values(array_filter($ids, is_string(...))) : null);

        return response()->json(['id' => $id, 'status' => Row::text($this->actions->ownExport($viewer, $id), 'status')], 202);
    }

    /**
     * Takes a bulk action: `{action, ids?, all?, state, input?}`. With `all`, every row that matches the state.
     *
     * @response array{done: int, refused: list<array{id: string, name: string, reason: string}>}
     */
    public function bulk(Request $request, string $grid): JsonResponse
    {
        $viewer = self::user($request);
        $state = $request->input('state');
        $ids = $request->input('ids');
        $input = $request->input('input');
        $result = $this->actions->bulk($viewer, $this->grids->grid($grid), $request->string('action')->toString(),
            $request->boolean('all') ? null : (is_array($ids) ? array_values(array_filter($ids, is_string(...))) : []),
            is_array($state) ? $state : [], is_array($input) ? array_filter($input, is_string(...), ARRAY_FILTER_USE_KEY) : []);

        return response()->json($result->toArray());
    }

    /**
     * One of the person's own exports.
     *
     * @response array{id: string, list: string, status: 'queued'|'building'|'ready'|'failed'|'expired', rows: int|null, requested_at: string, expires_at: string|null}
     */
    public function showExport(Request $request, string $id): JsonResponse
    {
        $row = $this->actions->ownExport(self::user($request), $id);
        $grid = Row::text($row, 'grid');
        $title = $grid;

        try {
            $title = $this->grids->grid($grid)->title();
        } catch (\Throwable) {
            // A list no longer offered keeps its key as its name.
        }

        return response()->json([
            'id' => Row::text($row, 'id'), 'list' => $title, 'status' => Row::text($row, 'status'),
            'rows' => isset($row->rows) && is_numeric($row->rows) ? (int) $row->rows : null,
            'requested_at' => Row::text($row, 'requested_at'), 'expires_at' => Row::maybeText($row, 'expires_at'),
        ]);
    }

    /** Downloads a finished export. */
    public function file(Request $request, string $id): StreamedResponse
    {
        $path = $this->actions->download(self::user($request), $id);

        return Storage::disk('local')->download($path, 'export-'.substr($id, -8).'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
