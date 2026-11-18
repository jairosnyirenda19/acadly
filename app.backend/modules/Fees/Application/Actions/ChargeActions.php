<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Application\FeesReach;
use Acadly\Modules\Fees\Application\Numbers;
use Acadly\Modules\Fees\Contracts\FeesAudit;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Domain\AdjustmentKind;
use Acadly\Modules\Fees\Domain\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** What one learner owes: an agreed plan, an adjustment, optional items, a shop sale (D-091). */
final class ChargeActions
{
    public function __construct(
        private readonly FeesReach $reach,
        private readonly PaymentActions $payments,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * A different plan agreed with one family. The school's plan stays, marked as replaced.
     *
     * @param  list<array{label: string, due_on: string, amount: int}>  $rows
     */
    public function agreePlan(User $by, string $chargeId, array $rows, string $reason): void
    {
        $charge = $this->charge($chargeId);
        $at = $this->reach->learner($by, Row::text($charge, 'learner_id'), FeesPermission::Adjust);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say why a different plan was agreed.']]);
        }
        if ($rows === [] || count($rows) > 24) {
            throw ValidationException::withMessages(['instalments' => ['Give from 1 to 24 instalments.']]);
        }
        if (array_sum(array_column($rows, 'amount')) !== Row::int($charge, 'total_minor')) {
            throw ValidationException::withMessages(['instalments' => ['The instalments must add up to the whole charge.']]);
        }
        foreach ($rows as $row) {
            if ($row['amount'] <= 0 || trim($row['label']) === '') {
                throw ValidationException::withMessages(['instalments' => ['Give each instalment a name and an amount.']]);
            }
        }

        DB::transaction(function () use ($by, $charge, $rows, $reason, $at): void {
            $now = $this->clock->now();
            $chargeId = Row::text($charge, 'id');
            DB::table('fee_instalments')->where('charge_id', $chargeId)->whereNull('superseded_at')->update(['superseded_at' => $now]);
            usort($rows, fn (array $a, array $b): int => $a['due_on'] <=> $b['due_on']);

            foreach ($rows as $i => $row) {
                DB::table('fee_instalments')->insert(['id' => Ids::next(), 'charge_id' => $chargeId, 'position' => $i + 1, 'label' => mb_substr(trim($row['label']), 0, 60),
                    'due_on' => $row['due_on'], 'amount_minor' => $row['amount'], 'plan' => 'agreed', 'reason' => mb_substr($reason, 0, 1000), 'created_by' => $by->id, 'created_at' => $now]);
            }

            $this->audit->record(FeesAudit::PlanAgreed, actor: $this->audit->actorFor($by), at: $at, reason: $reason,
                meta: ['charge' => Row::text($charge, 'number'), 'learner' => Row::text($charge, 'learner_id'), 'instalments' => count($rows)]);
        });
    }

    /** Less to pay on one charge: a discount, bursary, waiver or write-off. */
    public function adjust(User $by, string $chargeId, AdjustmentKind $kind, int $amount, string $reason): string
    {
        $charge = $this->charge($chargeId);
        $at = $this->reach->learner($by, Row::text($charge, 'learner_id'), FeesPermission::Adjust);
        $reason = trim($reason);
        $already = (int) DB::table('fee_adjustments')->where('charge_id', $chargeId)->sum('amount_minor');

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say why.']]);
        }
        if ($amount <= 0 || $amount > Row::int($charge, 'total_minor') - $already) {
            throw ValidationException::withMessages(['amount' => ['Give an amount up to what is left of the charge.']]);
        }

        return DB::transaction(function () use ($by, $charge, $kind, $amount, $reason, $at): string {
            $today = $this->reach->today();
            $number = Numbers::next(Numbers::ADJUSTMENT, $today);
            DB::table('fee_adjustments')->insert(['id' => Ids::next(), 'number' => $number, 'charge_id' => Row::text($charge, 'id'), 'learner_id' => Row::text($charge, 'learner_id'),
                'kind' => $kind->value, 'amount_minor' => $amount, 'reason' => mb_substr($reason, 0, 1000), 'created_by' => $by->id, 'made_on' => $today, 'created_at' => $this->clock->now()]);
            $this->audit->record(FeesAudit::Adjusted, actor: $this->audit->actorFor($by), at: $at, reason: $reason,
                meta: ['number' => $number, 'charge' => Row::text($charge, 'number'), 'learner' => Row::text($charge, 'learner_id'), 'kind' => $kind->value, 'amount_minor' => $amount]);

            return $number;
        });
    }

    /**
     * The optional items a learner takes, such as boarding. Applies to the next bill.
     *
     * @param  list<string>  $itemIds
     */
    public function setTakers(User $by, string $learnerId, array $itemIds): void
    {
        $at = $this->reach->learner($by, $learnerId, FeesPermission::Adjust);
        $optional = array_values(array_filter(DB::table('fee_items')->where('optional', true)->whereNull('retired_at')->pluck('id')->all(), 'is_string'));

        if (array_diff($itemIds, $optional) !== []) {
            throw ValidationException::withMessages(['items' => ['Choose from the optional items.']]);
        }

        DB::transaction(function () use ($by, $learnerId, $itemIds, $at): void {
            $now = $this->clock->now();
            $open = array_values(array_filter(DB::table('fee_item_takers')->where('learner_id', $learnerId)->whereNull('ended_at')->pluck('item_id')->all(), 'is_string'));
            DB::table('fee_item_takers')->where('learner_id', $learnerId)->whereNull('ended_at')->whereNotIn('item_id', $itemIds)->update(['ended_at' => $now]);

            foreach (array_diff($itemIds, $open) as $item) {
                DB::table('fee_item_takers')->insert(['id' => Ids::next(), 'item_id' => $item, 'learner_id' => $learnerId, 'set_by' => $by->id, 'started_at' => $now]);
            }

            $this->audit->record(FeesAudit::TakersChanged, actor: $this->audit->actorFor($by), at: $at, meta: ['learner' => $learnerId, 'items' => implode(',', $itemIds)]);
        });
    }

    /**
     * A sale from the school shop: a charge of its own, paid now or added to the statement.
     *
     * @param  list<array{item_id: string, quantity: int}>  $lines
     * @return array{charge_id: string, payment_id: string|null}
     */
    public function shopSale(User $by, string $learnerId, array $lines, ?PaymentMethod $payNow, ?string $reference, string $payerName): array
    {
        $at = $this->reach->learner($by, $learnerId, FeesPermission::Collect);
        $lines = array_values(array_filter($lines, fn (array $l): bool => $l['quantity'] > 0));
        $items = DB::table('fee_shop_items')->whereIn('id', array_column($lines, 'item_id'))->whereNull('retired_at')->get(['id', 'name', 'size', 'price_minor'])->keyBy('id');

        if ($lines === [] || $items->count() !== count(array_unique(array_column($lines, 'item_id')))) {
            throw ValidationException::withMessages(['items' => ['Choose at least one item from the price list.']]);
        }

        $total = 0;
        $price = [];

        foreach ($items as $id => $item) {
            $price[(string) $id] = $item;
        }

        foreach ($lines as $line) {
            $total += Row::int($price[$line['item_id']], 'price_minor') * min(99, $line['quantity']);
        }

        return DB::transaction(function () use ($by, $learnerId, $lines, $price, $total, $payNow, $reference, $payerName, $at): array {
            $today = $this->reach->today();
            $id = Ids::next();
            DB::table('fee_charges')->insert(['id' => $id, 'number' => Numbers::next(Numbers::BILL, $today), 'learner_id' => $learnerId, 'kind' => 'shop',
                'schedule_id' => null, 'label' => 'School shop', 'total_minor' => $total, 'issued_on' => $today, 'created_by' => $by->id, 'created_at' => $this->clock->now()]);

            foreach ($lines as $i => $line) {
                $item = $price[$line['item_id']];
                $size = Row::maybeText($item, 'size');
                DB::table('fee_charge_lines')->insert(['id' => Ids::next(), 'charge_id' => $id, 'item_id' => null, 'label' => Row::text($item, 'name').($size !== null ? ' ('.$size.')' : ''),
                    'quantity' => min(99, $line['quantity']), 'amount_minor' => Row::int($item, 'price_minor') * min(99, $line['quantity']), 'position' => $i + 1]);
            }

            DB::table('fee_instalments')->insert(['id' => Ids::next(), 'charge_id' => $id, 'position' => 1, 'label' => 'School shop', 'due_on' => $today,
                'amount_minor' => $total, 'plan' => 'school', 'created_by' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(FeesAudit::ShopSale, actor: $this->audit->actorFor($by), at: $at, meta: ['learner' => $learnerId, 'amount_minor' => $total, 'paid_now' => $payNow !== null]);

            $payment = $payNow === null ? null : $this->payments->record($by, $payNow, $reference, $today, $payerName, null, $total,
                [['learner_id' => $learnerId, 'amount' => $total, 'charge_id' => $id]]);

            return ['charge_id' => $id, 'payment_id' => $payment];
        });
    }

    private function charge(string $id): object
    {
        $charge = DB::table('fee_charges')->where('id', $id)->first(['id', 'number', 'learner_id', 'total_minor']);

        return $charge ?? throw new NotFoundHttpException;
    }
}
