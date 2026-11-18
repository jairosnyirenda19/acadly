<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Application\Actions\ChargeActions;
use Acadly\Modules\Fees\Application\Actions\PaymentActions;
use Acadly\Modules\Fees\Application\FeesReach;
use Acadly\Modules\Fees\Application\FeesViews;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Domain\AdjustmentKind;
use Acadly\Modules\Fees\Domain\PaymentMethod;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** A learner's fees, payments and receipts, plans, adjustments and the shop (D-091). Money is in tambala. */
final class FeesController
{
    /**
     * A learner's fees: the standing, the statement, the charges and the family.
     *
     * @response array{standing: array{instalments: list<array{id: string, charge_id: string, charge: string, position: int, label: string, due_on: string, amount: int, covered: int, state: string, plan: string}>, charged: int, reduced: int, paid: int, balance: int, due_now: int, left: int, credit: int, overdue_since: string|null}, statement: list<array{date: string, kind: string, detail: string, note: string|null, reference: string, charged: int|null, paid: int|null, balance: int, id: string, payment_id: string|null}>, charges: list<array{id: string, number: string, label: string, kind: string, total: int, reduced: int, issued_on: string, left: int, agreed: bool}>, optional: list<array{id: string, name: string, takes: bool}>, siblings: list<array{id: string, name: string, class: string, left: int, due_now: int}>, contact: array{id: string, name: string, relationship: string, phone: string, email: string|null, notices_by: string, main: bool}|null, can: array{collect: bool, adjust: bool}}
     */
    public function learner(Request $request, string $id, FeesViews $views): JsonResponse
    {
        return response()->json($views->learner(self::user($request), $id));
    }

    /**
     * Records a payment for one or more children of a family, and gives a receipt.
     *
     * @response 201 array{id: string}
     */
    public function pay(Request $request, PaymentActions $payments): JsonResponse
    {
        $request->validate([
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:80'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'payer_name' => ['required', 'string', 'max:120'],
            'payer_phone' => ['nullable', 'string', 'max:30'],
            'amount' => ['required', 'integer', 'min:1'],
            'shares' => ['required', 'array', 'min:1', 'max:12'],
            'shares.*.learner_id' => ['required', 'uuid'],
            'shares.*.amount' => ['required', 'integer', 'min:1'],
            'tell' => ['sometimes', 'boolean'],
        ]);

        $shares = [];

        foreach (Form::rows($request, 'shares') as $share) {
            $shares[] = ['learner_id' => Form::text($share, 'learner_id'), 'amount' => Form::int($share, 'amount')];
        }

        $id = $payments->record(self::user($request), PaymentMethod::from($request->string('method')->toString()), $request->string('reference')->toString(),
            $request->string('received_on')->toString(), $request->string('payer_name')->toString(), $request->string('payer_phone')->toString(),
            $request->integer('amount'), $shares, $request->boolean('tell', true));

        return response()->json(['id' => $id], 201);
    }

    /**
     * A receipt: who paid, how, and what each child's share paid.
     *
     * @response array{id: string, number: string, received_on: string, recorded_at: string, method: string, method_label: string, reference: string|null, payer: string, amount: int, received_by: string|null, void: array{number: string, reason: string, on: string}|null, shares: list<array{learner_id: string, name: string, class: string, amount: int, applied: list<array{label: string, amount: int}>, balance_after: int}>, can_cancel: bool}
     */
    public function receipt(Request $request, string $id, FeesViews $views): JsonResponse
    {
        return response()->json($views->receipt(self::user($request), $id));
    }

    /**
     * Cancels a receipt, with a reason.
     *
     * @response array{number: string}
     */
    public function cancel(Request $request, string $id, PaymentActions $payments): JsonResponse
    {
        $number = $payments->cancel(self::user($request), $id, $request->string('reason')->toString());

        return response()->json(['number' => $number]);
    }

    /**
     * A different instalment plan agreed with the family, with a reason.
     *
     * @response array{id: string}
     */
    public function plan(Request $request, string $id, ChargeActions $charges, FeesReach $reach): JsonResponse
    {
        $this->reachCharge($request, $id, $reach);
        $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'instalments' => ['required', 'array', 'min:1', 'max:24'],
            'instalments.*.label' => ['required', 'string', 'max:60'],
            'instalments.*.due_on' => ['required', 'date_format:Y-m-d'],
            'instalments.*.amount' => ['required', 'integer', 'min:1'],
        ]);

        $rows = [];

        foreach (Form::rows($request, 'instalments') as $row) {
            $rows[] = ['label' => Form::text($row, 'label'), 'due_on' => Form::text($row, 'due_on'), 'amount' => Form::int($row, 'amount')];
        }

        $charges->agreePlan(self::user($request), $id, $rows, $request->string('reason')->toString());

        return response()->json(['id' => $id]);
    }

    /**
     * A discount, bursary, waiver or write-off on one charge, with a reason.
     *
     * @response 201 array{number: string}
     */
    public function adjust(Request $request, string $id, ChargeActions $charges, FeesReach $reach): JsonResponse
    {
        $this->reachCharge($request, $id, $reach);
        $request->validate(['kind' => ['required', Rule::enum(AdjustmentKind::class)], 'amount' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000']]);
        $number = $charges->adjust(self::user($request), $id, AdjustmentKind::from($request->string('kind')->toString()), $request->integer('amount'), $request->string('reason')->toString());

        return response()->json(['number' => $number], 201);
    }

    /**
     * The optional items a learner takes, such as boarding.
     *
     * @response array{id: string}
     */
    public function optional(Request $request, string $id, ChargeActions $charges, FeesReach $reach): JsonResponse
    {
        $reach->learner(self::user($request), $id, FeesPermission::Adjust);
        $request->validate(['items' => ['present', 'array'], 'items.*' => ['uuid']]);
        $charges->setTakers(self::user($request), $id, Form::strings($request, 'items'));

        return response()->json(['id' => $id]);
    }

    /**
     * The shop price list.
     *
     * @response array{items: list<array{id: string, name: string, size: string|null, price: int, retired: bool}>}
     */
    public function shop(Request $request, Access $access): JsonResponse
    {
        $who = self::user($request);
        $items = [];

        foreach (DB::table('fee_shop_items')->orderBy('position')->get(['id', 'name', 'size', 'price_minor', 'retired_at']) as $i) {
            $items[] = ['id' => Row::text($i, 'id'), 'name' => Row::text($i, 'name'), 'size' => Row::maybeText($i, 'size'), 'price' => Row::int($i, 'price_minor'), 'retired' => $i->retired_at !== null];
        }

        // The price list says nothing about a place; it is shown to anyone who may sell or set it.
        return response()->json(['items' => $access->allows($who, FeesPermission::Manage) || self::holdsAnywhere($access, $who) ? $items : []]);
    }

    /**
     * A sale from the school shop for one learner: paid now, or added to the statement.
     *
     * @response 201 array{charge_id: string, payment_id: string|null}
     */
    public function sale(Request $request, ChargeActions $charges, FeesReach $reach): JsonResponse
    {
        $learner = $request->string('learner_id')->toString();
        $reach->learner(self::user($request), $learner, FeesPermission::Collect);
        $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:99'],
            'pay_now' => ['nullable', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:80'],
            'payer_name' => ['required_with:pay_now', 'nullable', 'string', 'max:120'],
        ]);

        $lines = [];

        foreach (Form::rows($request, 'lines') as $line) {
            $lines[] = ['item_id' => Form::text($line, 'item_id'), 'quantity' => Form::int($line, 'quantity')];
        }

        $method = $request->string('pay_now')->toString();

        return response()->json($charges->shopSale(self::user($request), $learner, $lines, $method !== '' ? PaymentMethod::from($method) : null,
            $request->string('reference')->toString(), $request->string('payer_name')->toString()), 201);
    }

    private function reachCharge(Request $request, string $chargeId, FeesReach $reach): void
    {
        $learner = DB::table('fee_charges')->where('id', $chargeId)->value('learner_id');
        $reach->learner(self::user($request), is_string($learner) ? $learner : throw new NotFoundHttpException, FeesPermission::Adjust);
    }

    private static function holdsAnywhere(Access $access, User $who): bool
    {
        foreach ($access->grants($who) as $grant) {
            if ($grant->permission === FeesPermission::Collect->value) {
                return true;
            }
        }

        return false;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
