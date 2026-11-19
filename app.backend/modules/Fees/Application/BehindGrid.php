<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Grid\BulkResult;
use Acadly\Kernel\Grid\GridColumn;
use Acadly\Kernel\Grid\GridDefinition;
use Acadly\Kernel\Grid\GridFilter;
use Acadly\Kernel\Grid\GridOption;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Application\Actions\DeskActions;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The learners behind their plan on the data grid (D-095): every learner
 * owing now since an instalment fell due, with the class, what is owed, on
 * what and for how many days. The ledger works the amounts out each time
 * from the facts; the grid then sorts, filters and pages them in the
 * database. Bulk: remind the families.
 */
final class BehindGrid implements GridDefinition
{
    /** The learners behind, as JSON, worked out once a request. */
    private ?string $rows = null;

    public function __construct(
        private readonly Access $access,
        private readonly ModuleActivation $activation,
        private readonly FeesViews $views,
        private readonly DeskActions $desk,
        private readonly Clock $clock,
    ) {}

    public function key(): string
    {
        return 'fee-behind';
    }

    public function title(): string
    {
        return 'Behind their plan';
    }

    public function query(User $viewer): Builder
    {
        if (! $this->activation->isActive('fees')) {
            throw new NotFoundHttpException;
        }

        if (! $this->sees($viewer)) {
            $this->access->check($viewer, FeesPermission::Collect);
        }

        // The worked-out rows go to the database as one JSON value, read back as a table.
        $source = DB::query()->fromRaw('jsonb_to_recordset(?::jsonb) as b(learner_id uuid, due_now bigint, since date, owed_on text, days int)', [$this->work()])
            ->select(['b.learner_id', 'b.due_now', 'b.since', 'b.owed_on', 'b.days']);

        return DB::query()->fromSub($source, 'b')
            ->join('learners as l', 'l.id', '=', 'b.learner_id')
            ->leftJoin('placements as pl', fn (JoinClause $j): JoinClause => $j->on('pl.learner_id', '=', 'b.learner_id')->whereNull('pl.ends_on'))
            ->leftJoin('academic_classes as k', 'k.id', '=', 'pl.class_id')
            ->select(['b.learner_id as id', 'b.due_now', 'b.since', 'b.owed_on', 'b.days', 'l.first_name', 'l.surname', 'k.id as class_id', 'k.name as class_name', 'k.division_id', 'k.level_code']);
    }

    public function idColumn(): string
    {
        return 'b.learner_id';
    }

    public function columns(): array
    {
        return [
            new GridColumn('learner', 'Learner', "lower(l.surname || ' ' || l.first_name)", always: true),
            new GridColumn('class', 'Class', "(k.level_code || ':' || lower(k.name))"),
            new GridColumn('owed_on', 'Owed on', 'b.owed_on'),
            new GridColumn('due_now', 'Due now', 'b.due_now'),
            new GridColumn('days', 'Overdue', 'b.days'),
        ];
    }

    public function filters(User $viewer): array
    {
        $classes = [];

        foreach (DB::table('academic_classes')->whereNull('ended_at')->whereIn('id', DB::table('placements')->whereNull('ends_on')->select('class_id'))
            ->orderBy('position')->get(['id', 'name']) as $c) {
            $id = Row::text($c, 'id');
            $classes[] = new GridOption($id, Row::text($c, 'name'), fn (Builder $q): Builder => $q->where('k.id', $id));
        }

        return [
            GridFilter::choice('class', 'Class', $classes),
            GridFilter::choice('overdue', 'Overdue', [
                new GridOption('week', 'Up to a week', fn (Builder $q): Builder => $q->where('b.days', '<=', 7)),
                new GridOption('month', 'Up to a month', fn (Builder $q): Builder => $q->whereBetween('b.days', [8, 30])),
                new GridOption('longer', 'More than a month', fn (Builder $q): Builder => $q->where('b.days', '>', 30)),
            ]),
            GridFilter::dates('since', 'Owing since', 'b.since'),
        ];
    }

    public function search(Builder $query, string $text): void
    {
        $q = '%'.mb_strtolower($text).'%';
        $query->where(fn (Builder $x): Builder => $x
            ->whereRaw("lower(l.first_name || ' ' || l.surname) like ?", [$q])
            ->orWhereRaw("lower(coalesce(k.name, '')) like ?", [$q])
            ->orWhereRaw('lower(b.owed_on) like ?', [$q]));
    }

    public function defaultSort(): array
    {
        return ['days', 'desc'];
    }

    public function rows(User $viewer, array $rows): array
    {
        return array_map(fn (object $r): array => [
            'id' => Row::text($r, 'id'),
            'name' => Row::text($r, 'first_name').' '.Row::text($r, 'surname'),
            'class_id' => Row::maybeText($r, 'class_id'),
            'class' => Row::maybeText($r, 'class_name') ?? '',
            'owed_on' => Row::text($r, 'owed_on'),
            'due_now' => Row::int($r, 'due_now'),
            'since' => substr(Row::text($r, 'since'), 0, 10),
            'days' => Row::int($r, 'days'),
        ], $rows);
    }

    public function plain(array $row, string $column): string|int|float|null
    {
        $value = match ($column) {
            'learner' => $row['name'] ?? null,
            'due_now' => is_int($row['due_now'] ?? null) ? round($row['due_now'] / 100, 2) : null,
            default => $row[$column] ?? null,
        };

        return is_string($value) || is_int($value) || is_float($value) ? $value : null;
    }

    public function canExport(User $viewer): bool
    {
        return $this->sees($viewer);
    }

    public function bulkActions(User $viewer): array
    {
        return $this->access->allows($viewer, FeesPermission::Collect) ? [['key' => 'remind', 'label' => 'Remind the families']] : [];
    }

    public function bulk(User $viewer, string $action, array $ids, array $input): BulkResult
    {
        $result = new BulkResult;

        if ($action !== 'remind') {
            return $result;
        }

        $sent = $this->desk->remind($viewer, $ids);

        for ($i = 0; $i < $sent; $i++) {
            $result->did();
        }

        return $result;
    }

    /** Works out who is behind, once a request, as JSON rows the database can sort and filter. */
    private function work(): string
    {
        return $this->rows ??= json_encode($this->views->behindOf($this->views->termStandings($this->views->currentTerms()), $this->clock->today()), JSON_THROW_ON_ERROR);
    }

    private function sees(User $viewer): bool
    {
        return $this->access->allows($viewer, FeesPermission::Collect) || $this->access->allows($viewer, FeesPermission::View);
    }
}
