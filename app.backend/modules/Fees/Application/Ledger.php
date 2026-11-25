<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Domain\AdjustmentKind;
use Acadly\Modules\Fees\Domain\PaymentMethod;
use Illuminate\Support\Facades\DB;

/**
 * The instalment engine (D-091). What a learner owes, and which instalment
 * each payment paid, are worked out from the facts every time: charges and
 * their instalments, adjustments, payments and cancelled receipts. Nothing
 * here is stored, so it can never disagree with the facts.
 *
 * The rule: adjustments reduce their own charge first. A payment for a
 * named charge (a shop sale) pays that charge first. Every other payment
 * pays the earliest instalment still owed, by due date, and carries on to
 * the next. What is left over is credit, and pays the next charge billed.
 *
 * @phpstan-type Instalment array{id: string, charge_id: string, charge: string, position: int, label: string, due_on: string, amount: int, covered: int, state: 'paid'|'part'|'overdue'|'due', plan: string}
 * @phpstan-type Standing array{instalments: list<Instalment>, charged: int, reduced: int, paid: int, balance: int, due_now: int, left: int, credit: int, overdue_since: string|null}
 */
final class Ledger
{
    public function __construct(private readonly Clock $clock) {}

    /** @return Standing */
    public function standing(string $learnerId, ?string $today = null): array
    {
        return $this->standings([$learnerId], $today)[$learnerId];
    }

    /**
     * @param  list<string>  $learnerIds
     * @return array<string, Standing>
     */
    public function standings(array $learnerIds, ?string $today = null): array
    {
        $today ??= $this->clock->now()->toDateString();
        $out = [];

        foreach (array_chunk(array_values(array_unique($learnerIds)), 500) as $chunk) {
            $out += $this->compute($chunk, $today);
        }

        return $out;
    }

    /**
     * The standing as it would be with one more payment, paid last: what a new receipt pays.
     *
     * @return Standing
     */
    public function standingWith(string $learnerId, int $amount, ?string $chargeId): array
    {
        return $this->compute([$learnerId], $this->clock->now()->toDateString(), [$learnerId => ['amount' => $amount, 'charge_id' => $chargeId]])[$learnerId];
    }

    /**
     * Every line of a learner's statement, oldest first, with the running balance.
     * A cancelled receipt stays, and its cancellation is a line of its own.
     *
     * @return list<array{date: string, kind: string, detail: string, note: string|null, reference: string, charged: int|null, paid: int|null, balance: int, id: string, payment_id: string|null}>
     */
    public function statement(string $learnerId): array
    {
        $rows = [];

        foreach (DB::table('fee_charges')->where('learner_id', $learnerId)->get(['id', 'number', 'label', 'total_minor', 'issued_on', 'created_at']) as $c) {
            $lines = DB::table('fee_charge_lines')->where('charge_id', $c->id)->orderBy('position')->get(['label', 'quantity', 'amount_minor']);
            $rows[] = ['date' => Row::text($c, 'issued_on'), 'at' => Row::text($c, 'created_at'), 'kind' => 'charge', 'detail' => Row::text($c, 'label'),
                'note' => implode(' · ', array_map(fn (object $l): string => Row::text($l, 'label').(Row::int($l, 'quantity') > 1 ? ' × '.Row::int($l, 'quantity') : '').' '.number_format(intdiv(Row::int($l, 'amount_minor'), 100)), $lines->all())),
                'reference' => Row::text($c, 'number'), 'charged' => Row::int($c, 'total_minor'), 'paid' => null, 'id' => Row::text($c, 'id'), 'payment_id' => null];
        }

        foreach (DB::table('fee_adjustments as a')->leftJoin('users as u', 'u.id', '=', 'a.created_by')->where('a.learner_id', $learnerId)
            ->get(['a.id', 'a.number', 'a.kind', 'a.amount_minor', 'a.reason', 'a.made_on', 'a.created_at', 'u.name as by']) as $a) {
            $rows[] = ['date' => Row::text($a, 'made_on'), 'at' => Row::text($a, 'created_at'), 'kind' => 'adjustment',
                'detail' => AdjustmentKind::from(Row::text($a, 'kind'))->label().': '.Row::text($a, 'reason'),
                'note' => Row::maybeText($a, 'by'), 'reference' => Row::text($a, 'number'), 'charged' => -Row::int($a, 'amount_minor'), 'paid' => null, 'id' => Row::text($a, 'id'), 'payment_id' => null];
        }

        $shares = DB::table('fee_payment_shares as s')->join('fee_payments as p', 'p.id', '=', 's.payment_id')
            ->leftJoin('fee_payment_voids as v', 'v.payment_id', '=', 'p.id')->leftJoin('users as vu', 'vu.id', '=', 'v.voided_by')
            ->where('s.learner_id', $learnerId)
            ->get(['p.id', 'p.number', 'p.received_on', 'p.created_at', 'p.method', 'p.reference', 'p.payer_name', 's.amount_minor',
                'v.number as void_number', 'v.reason as void_reason', 'v.voided_on', 'v.created_at as voided_at', 'vu.name as voided_by']);

        foreach ($shares as $s) {
            $others = DB::table('fee_payment_shares')->where('payment_id', $s->id)->count();
            $method = PaymentMethod::from(Row::text($s, 'method'));
            $rows[] = ['date' => Row::text($s, 'received_on'), 'at' => Row::text($s, 'created_at'), 'kind' => 'payment', 'detail' => $method->label(),
                'note' => implode(' · ', array_filter(['Paid by '.Row::text($s, 'payer_name'), Row::maybeText($s, 'reference'), $others > 1 ? $others.' children on this receipt' : null])),
                'reference' => Row::text($s, 'number'), 'charged' => null, 'paid' => Row::int($s, 'amount_minor'), 'id' => Row::text($s, 'number'), 'payment_id' => Row::text($s, 'id')];

            if (Row::maybeText($s, 'void_number') !== null) {
                $rows[] = ['date' => Row::text($s, 'voided_on'), 'at' => Row::text($s, 'voided_at'), 'kind' => 'void', 'detail' => Row::text($s, 'number'),
                    'note' => trim('“'.Row::text($s, 'void_reason').'” '.(Row::maybeText($s, 'voided_by') ?? '')), 'reference' => Row::text($s, 'void_number'),
                    'charged' => Row::int($s, 'amount_minor'), 'paid' => null, 'id' => Row::text($s, 'number').':void', 'payment_id' => Row::text($s, 'id')];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['date'], $a['at']] <=> [$b['date'], $b['at']]);
        $balance = 0;
        $out = [];

        foreach ($rows as $row) {
            $balance += ($row['charged'] ?? 0) - ($row['paid'] ?? 0);
            unset($row['at']);
            $out[] = [...$row, 'balance' => $balance];
        }

        return $out;
    }

    /**
     * What a new payment paid: the difference between two standings of one learner.
     *
     * @param  Standing  $before
     * @param  Standing  $after
     * @return list<array{label: string, amount: int}>
     */
    public static function applied(array $before, array $after): array
    {
        $was = [];

        foreach ($before['instalments'] as $i) {
            $was[$i['id']] = $i['covered'];
        }

        $lines = [];

        foreach ($after['instalments'] as $i) {
            $more = $i['covered'] - ($was[$i['id']] ?? 0);

            if ($more > 0) {
                $lines[] = ['label' => $i['charge'].' · '.$i['label'], 'amount' => $more];
            }
        }

        if ($after['credit'] > $before['credit']) {
            $lines[] = ['label' => 'Credit', 'amount' => $after['credit'] - $before['credit']];
        }

        return $lines;
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, array{amount: int, charge_id: string|null}>  $extra  a payment not yet kept, by learner
     * @return array<string, Standing>
     */
    private function compute(array $ids, string $today, array $extra = []): array
    {
        $charges = DB::table('fee_charges')->whereIn('learner_id', $ids)->get(['id', 'learner_id', 'label', 'total_minor', 'issued_on', 'created_at'])->keyBy('id');
        $instalments = DB::table('fee_instalments')->whereIn('charge_id', $charges->keys()->all())->whereNull('superseded_at')
            ->get(['id', 'charge_id', 'position', 'label', 'due_on', 'amount_minor', 'plan']);
        $adjustments = DB::table('fee_adjustments')->whereIn('learner_id', $ids)->orderBy('created_at')->get(['learner_id', 'charge_id', 'amount_minor']);
        $shares = DB::table('fee_payment_shares as s')->join('fee_payments as p', 'p.id', '=', 's.payment_id')
            ->leftJoin('fee_payment_voids as v', 'v.payment_id', '=', 'p.id')->whereNull('v.payment_id')->whereIn('s.learner_id', $ids)
            ->orderBy('p.received_on')->orderBy('p.created_at')->get(['s.learner_id', 's.charge_id', 's.amount_minor']);

        foreach ($extra as $learner => $share) {
            $shares->push((object) ['learner_id' => $learner, 'charge_id' => $share['charge_id'], 'amount_minor' => $share['amount']]);
        }

        // Each list by learner, once, in its own order: each learner then reads only their own rows.
        $instalmentsOf = [];

        foreach ($instalments as $i) {
            $charge = $charges->get(Row::text($i, 'charge_id'));

            if ($charge !== null) {
                $instalmentsOf[Row::text($charge, 'learner_id')][] = $i;
            }
        }

        $adjustmentsOf = [];

        foreach ($adjustments as $a) {
            $adjustmentsOf[Row::text($a, 'learner_id')][] = $a;
        }

        $sharesOf = [];

        foreach ($shares as $s) {
            $sharesOf[Row::text($s, 'learner_id')][] = $s;
        }

        $chargesOf = [];

        foreach ($charges as $c) {
            $chargesOf[Row::text($c, 'learner_id')][] = $c;
        }

        $out = [];

        foreach ($ids as $id) {
            $mine = [];

            foreach ($instalmentsOf[$id] ?? [] as $i) {
                $charge = $charges->get(Row::text($i, 'charge_id'));

                if ($charge !== null && Row::text($charge, 'learner_id') === $id) {
                    $mine[] = ['id' => Row::text($i, 'id'), 'charge_id' => Row::text($i, 'charge_id'), 'charge' => Row::text($charge, 'label'), 'position' => Row::int($i, 'position'),
                        'label' => Row::text($i, 'label'), 'due_on' => Row::text($i, 'due_on'), 'amount' => Row::int($i, 'amount_minor'), 'covered' => 0, 'state' => 'due',
                        'plan' => Row::text($i, 'plan'), 'issued' => Row::text($charge, 'created_at')];
                }
            }

            usort($mine, fn (array $a, array $b): int => [$a['due_on'], $a['issued'], $a['position']] <=> [$b['due_on'], $b['issued'], $b['position']]);
            $credit = 0;
            $reduced = 0;
            $paid = 0;

            $apply = function (int $amount, ?string $chargeId) use (&$mine): int {
                foreach ($mine as &$i) {
                    if ($amount <= 0) {
                        break;
                    }

                    if ($chargeId !== null && $i['charge_id'] !== $chargeId) {
                        continue;
                    }

                    $take = min($amount, $i['amount'] - $i['covered']);
                    $i['covered'] += $take;
                    $amount -= $take;
                }

                unset($i);

                return $amount;
            };

            foreach ($adjustmentsOf[$id] ?? [] as $a) {
                if ($a->learner_id === $id) {
                    $reduced += Row::int($a, 'amount_minor');
                    $left = $apply(Row::int($a, 'amount_minor'), Row::text($a, 'charge_id'));
                    $credit += $apply($left, null);
                }
            }

            // A payment for a named charge pays it first, wherever it falls in time.
            foreach ([true, false] as $directed) {
                foreach ($sharesOf[$id] ?? [] as $s) {
                    if ($s->learner_id !== $id || ($s->charge_id !== null) !== $directed) {
                        continue;
                    }

                    $paid += Row::int($s, 'amount_minor');
                    $left = $apply(Row::int($s, 'amount_minor'), Row::maybeText($s, 'charge_id'));
                    $credit += $apply($left, null);
                }
            }

            $charged = 0;

            foreach ($chargesOf[$id] ?? [] as $c) {
                if ($c->learner_id === $id) {
                    $charged += Row::int($c, 'total_minor');
                }
            }

            $dueNow = 0;
            $left = 0;
            $since = null;
            $list = [];

            foreach ($mine as $i) {
                $owed = $i['amount'] - $i['covered'];
                $i['state'] = $owed <= 0 ? 'paid' : ($i['due_on'] < $today ? 'overdue' : ($i['covered'] > 0 ? 'part' : 'due'));
                $left += max(0, $owed);

                if ($i['due_on'] <= $today) {
                    $dueNow += max(0, $owed);
                }

                if ($i['state'] === 'overdue') {
                    $since = $since === null || $i['due_on'] < $since ? $i['due_on'] : $since;
                }

                unset($i['issued']);
                $list[] = $i;
            }

            $out[$id] = ['instalments' => $list, 'charged' => $charged, 'reduced' => $reduced, 'paid' => $paid, 'balance' => $charged - $reduced - $paid,
                'due_now' => $dueNow, 'left' => $left, 'credit' => $credit, 'overdue_since' => $since];
        }

        return $out;
    }
}
