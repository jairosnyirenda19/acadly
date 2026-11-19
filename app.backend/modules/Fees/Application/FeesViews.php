<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Application\Actions\DeskActions;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Domain\PaymentMethod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the fee screens read: a learner's fees, a receipt, the desk (D-091).
 *
 * @phpstan-import-type Term from TermFees
 * @phpstan-import-type Standing from Ledger
 */
final class FeesViews
{
    public function __construct(
        private readonly FeesReach $reach,
        private readonly Ledger $ledger,
        private readonly Access $access,
        private readonly EnrolmentDirectory $directory,
        private readonly AcademicCalendar $calendar,
        private readonly TermFees $terms,
        private readonly Clock $clock,
        private readonly FeeOffices $offices,
    ) {}

    /** @return array<string, mixed> */
    public function learner(User $viewer, string $learnerId): array
    {
        $this->reach->learner($viewer, $learnerId, FeesPermission::View);
        $standing = $this->ledger->standing($learnerId);
        $charges = [];

        foreach (DB::table('fee_charges')->where('learner_id', $learnerId)->orderBy('issued_on')->orderBy('created_at')->get(['id', 'number', 'label', 'kind', 'total_minor', 'issued_on']) as $c) {
            $reduced = (int) DB::table('fee_adjustments')->where('charge_id', $c->id)->sum('amount_minor');
            $mine = array_values(array_filter($standing['instalments'], fn (array $i): bool => $i['charge_id'] === $c->id));
            $charges[] = ['id' => Row::text($c, 'id'), 'number' => Row::text($c, 'number'), 'label' => Row::text($c, 'label'), 'kind' => Row::text($c, 'kind'),
                'total' => Row::int($c, 'total_minor'), 'reduced' => $reduced, 'issued_on' => Row::text($c, 'issued_on'),
                'left' => array_sum(array_map(fn (array $i): int => max(0, $i['amount'] - $i['covered']), $mine)),
                'agreed' => array_filter($mine, fn (array $i): bool => $i['plan'] === 'agreed') !== []];
        }

        $optional = [];
        $takes = array_flip(array_filter(DB::table('fee_item_takers')->where('learner_id', $learnerId)->whereNull('ended_at')->pluck('item_id')->all(), 'is_string'));

        foreach (DB::table('fee_items')->where('optional', true)->whereNull('retired_at')->orderBy('position')->get(['id', 'name']) as $item) {
            $optional[] = ['id' => Row::text($item, 'id'), 'name' => Row::text($item, 'name'), 'takes' => isset($takes[Row::text($item, 'id')])];
        }

        $siblings = [];
        $ids = $this->directory->siblingsOf($learnerId);
        $names = $this->directory->names($ids);
        $theirs = $this->ledger->standings($ids);

        foreach ($ids as $id) {
            if (isset($names[$id]) && $this->reach->may($viewer, FeesPermission::Collect, $id)) {
                $siblings[] = ['id' => $id, 'name' => $names[$id], 'class' => $this->className($id), 'left' => $theirs[$id]['left'], 'due_now' => $theirs[$id]['due_now']];
            }
        }

        return [
            'standing' => $standing,
            'statement' => $this->ledger->statement($learnerId),
            'charges' => $charges,
            'optional' => $optional,
            'siblings' => $siblings,
            'contact' => $this->directory->contactsOf($learnerId)[0] ?? null,
            'can' => [
                'collect' => $this->reach->may($viewer, FeesPermission::Collect, $learnerId),
                'adjust' => $this->reach->may($viewer, FeesPermission::Adjust, $learnerId),
            ],
        ];
    }

    /** @return array{id: string, number: string, received_on: string, recorded_at: string, method: string, method_label: string, reference: string|null, payer: string, amount: int, received_by: string|null, void: array{number: string, reason: string, on: string}|null, shares: list<array{learner_id: string, name: string, class: string, amount: int, applied: list<array{label: string, amount: int}>, balance_after: int}>, can_cancel: bool} */
    public function receipt(User $viewer, string $paymentId): array
    {
        $p = DB::table('fee_payments as p')->leftJoin('users as u', 'u.id', '=', 'p.received_by')->leftJoin('fee_payment_voids as v', 'v.payment_id', '=', 'p.id')
            ->where('p.id', $paymentId)->first(['p.id', 'p.number', 'p.received_on', 'p.created_at', 'p.method', 'p.reference', 'p.payer_name', 'p.payer_phone', 'p.amount_minor',
                'u.name as received_by', 'v.number as void_number', 'v.reason as void_reason', 'v.voided_on']);

        if ($p === null) {
            throw new NotFoundHttpException;
        }

        $shares = DB::table('fee_payment_shares')->where('payment_id', $paymentId)->get(['id', 'learner_id', 'amount_minor', 'applied', 'balance_after_minor']);
        $seen = false;

        foreach ($shares as $s) {
            $learner = Row::text($s, 'learner_id');
            $seen = $seen || $this->reach->may($viewer, FeesPermission::View, $learner) || $this->reach->may($viewer, FeesPermission::Collect, $learner);
        }

        if (! $seen) {
            throw new NotFoundHttpException;
        }

        $names = $this->directory->names(array_values(array_map(fn (object $s): string => Row::text($s, 'learner_id'), $shares->all())));

        return [
            'id' => Row::text($p, 'id'),
            'number' => Row::text($p, 'number'),
            'received_on' => Row::text($p, 'received_on'),
            'recorded_at' => Row::text($p, 'created_at'),
            'method' => Row::text($p, 'method'),
            'method_label' => PaymentMethod::from(Row::text($p, 'method'))->label(),
            'reference' => Row::maybeText($p, 'reference'),
            'payer' => Row::text($p, 'payer_name'),
            'amount' => Row::int($p, 'amount_minor'),
            'received_by' => Row::maybeText($p, 'received_by'),
            'void' => Row::maybeText($p, 'void_number') === null ? null : ['number' => Row::text($p, 'void_number'), 'reason' => Row::text($p, 'void_reason'), 'on' => Row::text($p, 'voided_on')],
            'shares' => array_values(array_map(fn (object $s): array => [
                'learner_id' => Row::text($s, 'learner_id'),
                'name' => $names[Row::text($s, 'learner_id')] ?? '',
                'class' => $this->className(Row::text($s, 'learner_id')),
                'amount' => Row::int($s, 'amount_minor'),
                'applied' => self::lines(Row::text($s, 'applied')),
                'balance_after' => Row::int($s, 'balance_after_minor'),
            ], $shares->all())),
            'can_cancel' => Row::maybeText($p, 'void_number') === null && $shares->every(fn (object $s): bool => $this->reach->may($viewer, FeesPermission::Adjust, Row::text($s, 'learner_id'))),
        ];
    }

    /**
     * The term each division is in now, with its fee schedule.
     *
     * @return array<string, Term> division id => term
     */
    public function currentTerms(): array
    {
        $terms = [];

        foreach ($this->terms->terms() as $term) {
            $current = $this->terms->currentTerm($term['division_id']);

            if ($current !== null && $current['period_id'] === $term['period_id'] && ! isset($terms[$term['division_id']])) {
                $terms[$term['division_id']] = $term;
            }
        }

        return $terms;
    }

    /**
     * Where every learner billed this term stands.
     *
     * @param  array<string, Term>  $terms
     * @return array<string, Standing>
     */
    public function termStandings(array $terms): array
    {
        $learners = [];

        foreach (DB::table('fee_charges')->whereIn('schedule_id', $this->schedulesOf($terms))->get(['learner_id']) as $c) {
            $learners[Row::text($c, 'learner_id')] = true;
        }

        return $this->ledger->standings(array_map('strval', array_keys($learners)));
    }

    /**
     * The fee schedules of these terms.
     *
     * @param  array<string, Term>  $terms
     * @return list<string>
     */
    private function schedulesOf(array $terms): array
    {
        $schedules = [];

        foreach ($terms as $term) {
            $schedule = $this->terms->scheduleId($term['period_id'], $term['division_id']);

            if ($schedule !== null) {
                $schedules[] = $schedule;
            }
        }

        return $schedules;
    }

    /**
     * The learners behind their plan: owing now, since an instalment fell due.
     *
     * @param  array<string, Standing>  $standings
     * @return list<array{learner_id: string, due_now: int, since: string, owed_on: string, days: int}>
     */
    public function behindOf(array $standings, string $today): array
    {
        $behind = [];

        foreach ($standings as $id => $s) {
            if ($s['due_now'] <= 0 || $s['overdue_since'] === null) {
                continue;
            }

            $first = null;

            foreach ($s['instalments'] as $i) {
                if ($i['state'] === 'overdue') {
                    $first ??= $i;
                }
            }

            $behind[] = ['learner_id' => (string) $id, 'due_now' => $s['due_now'], 'since' => $s['overdue_since'], 'owed_on' => $first === null ? '' : $first['charge'].' · '.$first['label'],
                'days' => (int) round((strtotime($today) - strtotime($s['overdue_since'])) / 86400)];
        }

        return $behind;
    }

    /** The class a learner is in now, by name. */
    public function classOf(string $learnerId): string
    {
        return $this->className($learnerId);
    }

    /** @return array<string, mixed> */
    public function desk(User $viewer): array
    {
        $this->access->check($viewer, FeesPermission::Collect);
        $today = $this->clock->today();
        $terms = $this->currentTerms();
        $standings = $this->termStandings($terms);
        // Only the learners of the place worked in (D-099).
        $classes = $this->reach->classesInPlace($viewer);
        if ($classes !== null) {
            $mine = DB::table('placements')->whereNull('ends_on')->whereIn('class_id', $classes ?: ['00000000-0000-0000-0000-000000000000'])->pluck('learner_id')->map(fn ($v): string => is_string($v) ? $v : '')->all();
            $standings = array_intersect_key($standings, array_flip($mine));
        }
        $behind = $this->behindOf($standings, $today);
        $paidInFull = 0;
        $dueUnpaid = 0;
        $credit = 0;

        foreach ($standings as $id => $s) {
            $credit += $s['credit'];
            $paidInFull += $s['left'] === 0 ? 1 : 0;

            if ($s['due_now'] > 0 && $s['overdue_since'] !== null) {
                $dueUnpaid += $s['due_now'];
            }
        }

        usort($behind, fn (array $a, array $b): int => [$b['days'], $b['due_now']] <=> [$a['days'], $a['due_now']]);
        $names = $this->directory->names(array_column($behind, 'learner_id'));
        $behind = array_map(fn (array $b): array => [...$b, 'name' => $names[$b['learner_id']] ?? '', 'class' => $this->className($b['learner_id'])], array_slice($behind, 0, 200));

        $ranges = array_map(fn (array $t): array => [$t['starts_on'], $t['ends_on']], array_values($terms));
        // Terms of different divisions share their dates: each payment is counted once.
        $collected = $ranges === [] ? 0 : (int) $this->reach->keepPayments(DB::table('fee_payments as p'), $viewer)->leftJoin('fee_payment_voids as v', 'v.payment_id', '=', 'p.id')->whereNull('v.payment_id')
            ->where(function (Builder $q) use ($ranges): void {
                foreach ($ranges as [$from, $to]) {
                    $q->orWhereBetween('p.received_on', [$from, $to]);
                }
            })->sum('p.amount_minor');

        $todayRows = $this->reach->keepPayments(DB::table('fee_payments as p'), $viewer)->leftJoin('fee_payment_voids as v', 'v.payment_id', '=', 'p.id')->leftJoin('users as u', 'u.id', '=', 'p.received_by')
            ->where('p.received_on', $today)->get(['p.method', 'p.amount_minor', 'v.payment_id as voided', 'u.name as by']);
        $methods = [];
        $cashiers = [];

        foreach (PaymentMethod::cases() as $m) {
            $live = $todayRows->filter(fn (object $r): bool => $r->method === $m->value && $r->voided === null);
            $methods[] = ['method' => $m->value, 'label' => $m->label(), 'receipts' => $live->count(), 'amount' => array_sum(array_map(fn (object $r): int => Row::int($r, 'amount_minor'), $live->all()))];
        }

        foreach ($todayRows->filter(fn (object $r): bool => $r->voided === null)->groupBy('by') as $name => $rows) {
            $cashiers[] = ['name' => (string) $name, 'receipts' => $rows->count()];
        }

        $office = $this->offices->current();
        $closed = $office === null ? null : DB::table('fee_days')->where('day', $today)->where('office_id', $office)->first(['expected_cash_minor', 'counted_cash_minor', 'closed_at']);

        return [
            'today' => $today,
            'terms' => array_map(fn (array $t): array => ['division' => $t['division'], 'name' => $t['name'], 'year' => $t['year']], array_values($terms)),
            'billed' => (int) $this->reach->keepLearners(DB::table('fee_charges')->whereIn('schedule_id', $this->schedulesOf($terms)), 'learner_id', $viewer)->sum('total_minor'),
            'billed_learners' => count($standings),
            'collected' => $collected,
            'due_unpaid' => $dueUnpaid,
            'behind_count' => count($standings) === 0 ? 0 : count(array_filter($standings, fn (array $s): bool => $s['due_now'] > 0 && $s['overdue_since'] !== null)),
            'paid_in_full' => $paidInFull,
            'credit' => $credit,
            'credit_learners' => count(array_filter($standings, fn (array $s): bool => $s['credit'] > 0)),
            'behind' => $behind,
            'takings' => $methods,
            'cashiers' => $cashiers,
            'cancelled_today' => $todayRows->filter(fn (object $r): bool => $r->voided !== null)->count(),
            'expected_cash' => DeskActions::cashOn($today, $this->reach, $viewer),
            'office' => $office,
            // D-100: who may take money here. Null when the place spans offices.
            'staffing' => $office === null ? null : ['named' => FeeOffices::staffOf($office) !== [], 'works' => FeeOffices::works($viewer, $office)],
            'closed' => $closed === null ? null : ['expected' => Row::int($closed, 'expected_cash_minor'), 'counted' => Row::int($closed, 'counted_cash_minor'), 'at' => Row::text($closed, 'closed_at')],
        ];
    }

    private function className(string $learnerId): string
    {
        $place = $this->directory->placeOf($learnerId);

        return $place === null ? '' : ($this->calendar->classNames([$place['class_id']])[$place['class_id']]['name'] ?? '');
    }

    /** @return list<array{label: string, amount: int}> */
    private static function lines(string $json): array
    {
        $decoded = json_decode($json, true);
        $out = [];

        foreach (is_array($decoded) ? $decoded : [] as $line) {
            if (is_array($line) && is_string($line['label'] ?? null) && is_int($line['amount'] ?? null)) {
                $out[] = ['label' => $line['label'], 'amount' => $line['amount']];
            }
        }

        return $out;
    }
}
