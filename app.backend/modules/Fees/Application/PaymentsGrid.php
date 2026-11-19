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
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Domain\PaymentMethod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The payments ledger on the data grid (D-095): every payment received, its
 * receipt, who paid, for which learners, how, how much and who received it.
 * A cancelled receipt stays in the ledger, marked cancelled, and is not
 * counted. Seen by those who keep the fees desk for the whole school.
 */
final class PaymentsGrid implements GridDefinition
{
    /** The learners a payment was shared between, by name. */
    private const string LEARNERS = "(select string_agg(l.first_name || ' ' || l.surname, ', ' order by l.surname, l.first_name) "
        .'from fee_payment_shares s join learners l on l.id = s.learner_id where s.payment_id = p.id)';

    public function __construct(
        private readonly Access $access,
        private readonly ModuleActivation $activation,
    ) {}

    public function key(): string
    {
        return 'fee-payments';
    }

    public function title(): string
    {
        return 'Payments received';
    }

    public function query(User $viewer): Builder
    {
        if (! $this->activation->isActive('fees')) {
            throw new NotFoundHttpException;
        }

        if (! $this->sees($viewer)) {
            $this->access->check($viewer, FeesPermission::Collect);
        }

        return DB::table('fee_payments as p')
            ->leftJoin('fee_payment_voids as v', 'v.payment_id', '=', 'p.id')
            ->leftJoin('users as u', 'u.id', '=', 'p.received_by')
            ->select(['p.id', 'p.number', 'p.received_on', 'p.method', 'p.reference', 'p.payer_name', 'p.amount_minor', 'p.created_at', 'v.number as void_number', 'u.name as received_by'])
            ->selectRaw(self::LEARNERS.' as learners');
    }

    public function idColumn(): string
    {
        return 'p.id';
    }

    public function columns(): array
    {
        return [
            new GridColumn('receipt', 'Receipt', 'p.number', always: true),
            new GridColumn('received_on', 'Received', 'p.received_on'),
            new GridColumn('learners', 'Learners', 'lower('.self::LEARNERS.')'),
            new GridColumn('payer', 'Paid by', 'lower(p.payer_name)'),
            new GridColumn('method', 'Method', 'p.method'),
            new GridColumn('amount', 'Amount', 'p.amount_minor'),
            new GridColumn('received_by', 'Received by', 'lower(u.name)'),
            new GridColumn('state', 'State', '(v.number is not null)'),
        ];
    }

    public function filters(User $viewer): array
    {
        return [
            GridFilter::choice('method', 'Method', array_map(fn (PaymentMethod $m): GridOption => new GridOption($m->value, $m->label(),
                fn (Builder $q): Builder => $q->where('p.method', $m->value)), PaymentMethod::cases())),
            GridFilter::choice('state', 'State', [
                new GridOption('counted', 'Counted', fn (Builder $q): Builder => $q->whereNull('v.number')),
                new GridOption('cancelled', 'Cancelled', fn (Builder $q): Builder => $q->whereNotNull('v.number')),
            ]),
            GridFilter::dates('received', 'Received', 'p.received_on'),
        ];
    }

    public function search(Builder $query, string $text): void
    {
        $q = '%'.mb_strtolower($text).'%';
        $query->where(fn (Builder $x): Builder => $x
            ->whereRaw('lower(p.number) like ?', [$q])
            ->orWhereRaw('lower(p.payer_name) like ?', [$q])
            ->orWhereRaw("lower(coalesce(p.reference, '')) like ?", [$q])
            ->orWhereRaw('lower('.self::LEARNERS.') like ?', [$q]));
    }

    public function defaultSort(): array
    {
        return ['received_on', 'desc'];
    }

    public function rows(User $viewer, array $rows): array
    {
        return array_map(function (object $r): array {
            $method = PaymentMethod::tryFrom(Row::text($r, 'method'));
            $void = Row::maybeText($r, 'void_number');

            return [
                'id' => Row::text($r, 'id'),
                'receipt' => Row::text($r, 'number'),
                'received_on' => substr(Row::text($r, 'received_on'), 0, 10),
                'learners' => Row::maybeText($r, 'learners') ?? '',
                'payer' => Row::text($r, 'payer_name'),
                'method' => $method->value ?? Row::text($r, 'method'),
                'method_label' => $method?->label() ?? Row::text($r, 'method'),
                'reference' => Row::maybeText($r, 'reference'),
                'amount' => Row::int($r, 'amount_minor'),
                'received_by' => Row::maybeText($r, 'received_by'),
                'cancelled' => $void !== null,
                'void_number' => $void,
            ];
        }, $rows);
    }

    public function plain(array $row, string $column): string|int|float|null
    {
        $value = match ($column) {
            'method' => $row['method_label'] ?? null,
            'amount' => is_int($row['amount'] ?? null) ? round($row['amount'] / 100, 2) : null,
            'state' => ($row['cancelled'] ?? false) === true ? 'Cancelled' : 'Counted',
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
        return [];
    }

    public function bulk(User $viewer, string $action, array $ids, array $input): BulkResult
    {
        return new BulkResult;
    }

    /** The ledger is the whole school's: it needs fees permission for the whole school. */
    private function sees(User $viewer): bool
    {
        return $this->access->allows($viewer, FeesPermission::Collect) || $this->access->allows($viewer, FeesPermission::View);
    }
}
