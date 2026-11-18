<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Application\Actions\DeskActions;
use Acadly\Modules\Fees\Application\Actions\TermFeeActions;
use Acadly\Modules\Fees\Application\FeesViews;
use Acadly\Modules\Fees\Application\TermFees;
use Acadly\Modules\Fees\Contracts\FeesAudit;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Domain\PaymentMethod;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The school's fees and the fees desk: terms, billing, items, the shop list, the day, reminders, the download (D-091). */
final class FeesSettingsController
{
    /**
     * The terms of this school year and the next, by division, and whether each is billed.
     *
     * @response array{terms: list<array{period_id: string, division_id: string, division: string, year: string, name: string, starts_on: string, ends_on: string, state: string, learners: int}>}
     */
    public function terms(Request $request, Access $access, TermFees $terms): JsonResponse
    {
        $access->check(self::user($request), FeesPermission::Manage);
        $out = [];

        foreach ($terms->terms() as $t) {
            $schedule = $terms->scheduleId($t['period_id'], $t['division_id']);
            $billed = $schedule !== null && DB::table('fee_schedules')->where('id', $schedule)->whereNotNull('billed_at')->exists();
            $out[] = ['period_id' => $t['period_id'], 'division_id' => $t['division_id'], 'division' => $t['division'], 'year' => $t['year'], 'name' => $t['name'],
                'starts_on' => $t['starts_on'], 'ends_on' => $t['ends_on'], 'state' => $billed ? 'billed' : ($schedule !== null ? 'set' : 'not_set'),
                'learners' => $schedule === null ? 0 : DB::table('fee_charges')->where('schedule_id', $schedule)->count()];
        }

        return response()->json(['terms' => $out]);
    }

    /**
     * The fees of one term: items, levels, amounts, the plan, and what billing would do.
     *
     * @response array{term: array{period_id: string, division_id: string, division: string, year: string, name: string, starts_on: string, ends_on: string}, items: list<array{id: string, name: string, optional: bool, retired: bool}>, levels: list<array{code: string, label: string}>, amounts: list<array{item_id: string, level_code: string, amount: int}>, plan: list<array{label: string, due_on: string, share_bp: int}>, billed: bool, billed_learners: int, to_bill: array{learners: int, total: int}}
     */
    public function term(Request $request, string $period, string $division, Access $access, TermFees $terms): JsonResponse
    {
        $access->check(self::user($request), FeesPermission::Manage);
        $term = $terms->term($period, $division);
        $schedule = $terms->scheduleId($period, $division);
        $amounts = [];
        $plan = [];

        if ($schedule !== null) {
            foreach (DB::table('fee_schedule_amounts')->where('schedule_id', $schedule)->get(['item_id', 'level_code', 'amount_minor']) as $a) {
                $amounts[] = ['item_id' => Row::text($a, 'item_id'), 'level_code' => Row::text($a, 'level_code'), 'amount' => Row::int($a, 'amount_minor')];
            }

            foreach (DB::table('fee_schedule_instalments')->where('schedule_id', $schedule)->orderBy('position')->get(['label', 'due_on', 'share_bp']) as $p) {
                $plan[] = ['label' => Row::text($p, 'label'), 'due_on' => Row::text($p, 'due_on'), 'share_bp' => Row::int($p, 'share_bp')];
            }
        }

        $bills = $terms->bills($term);
        unset($term['year_id']);

        return response()->json([
            'term' => $term,
            'items' => self::itemList(),
            'levels' => $terms->levels($division),
            'amounts' => $amounts,
            'plan' => $plan,
            'billed' => $schedule !== null && DB::table('fee_schedules')->where('id', $schedule)->whereNotNull('billed_at')->exists(),
            'billed_learners' => $schedule === null ? 0 : DB::table('fee_charges')->where('schedule_id', $schedule)->count(),
            'to_bill' => ['learners' => count($bills), 'total' => array_sum(array_column($bills, 'total'))],
        ]);
    }

    /**
     * Saves the fees of a term. Refused once the term is billed.
     *
     * @response array{saved: bool}
     */
    public function saveTerm(Request $request, string $period, string $division, Access $access, TermFeeActions $actions): JsonResponse
    {
        $access->check(self::user($request), FeesPermission::Manage);
        $request->validate([
            'amounts' => ['present', 'array'],
            'amounts.*.item_id' => ['required', 'uuid'],
            'amounts.*.level_code' => ['required', 'string', 'max:20'],
            'amounts.*.amount' => ['required', 'integer', 'min:0'],
            'plan' => ['required', 'array', 'min:1', 'max:6'],
            'plan.*.label' => ['required', 'string', 'max:60'],
            'plan.*.due_on' => ['required', 'date_format:Y-m-d'],
            'plan.*.share_bp' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $amounts = [];
        $plan = [];

        foreach (Form::rows($request, 'amounts') as $a) {
            $amounts[] = ['item_id' => Form::text($a, 'item_id'), 'level_code' => Form::text($a, 'level_code'), 'amount' => Form::int($a, 'amount')];
        }

        foreach (Form::rows($request, 'plan') as $p) {
            $plan[] = ['label' => Form::text($p, 'label'), 'due_on' => Form::text($p, 'due_on'), 'share_bp' => Form::int($p, 'share_bp')];
        }

        $actions->saveTerm(self::user($request), $period, $division, $amounts, $plan);

        return response()->json(['saved' => true]);
    }

    /**
     * Bills the term to every learner placed in it and not billed yet.
     *
     * @response array{learners: int, total: int}
     */
    public function bill(Request $request, string $period, string $division, TermFeeActions $actions): JsonResponse
    {
        return response()->json($actions->bill(self::user($request), $period, $division));
    }

    /**
     * The fee items.
     *
     * @response array{items: list<array{id: string, name: string, optional: bool, retired: bool}>}
     */
    public function items(Request $request, Access $access): JsonResponse
    {
        $access->check(self::user($request), FeesPermission::Manage);

        return response()->json(['items' => self::itemList()]);
    }

    /**
     * Saves the fee items.
     *
     * @response array{items: list<array{id: string, name: string, optional: bool, retired: bool}>}
     */
    public function saveItems(Request $request, Access $access, TermFeeActions $actions): JsonResponse
    {
        $access->check(self::user($request), FeesPermission::Manage);
        $request->validate(['items' => ['required', 'array', 'max:40'], 'items.*.id' => ['nullable', 'uuid'], 'items.*.name' => ['required', 'string', 'max:80'],
            'items.*.optional' => ['required', 'boolean'], 'items.*.retired' => ['required', 'boolean']]);
        $items = [];

        foreach (Form::rows($request, 'items') as $i) {
            $items[] = ['id' => Form::maybe($i, 'id'), 'name' => Form::text($i, 'name'), 'optional' => Form::bool($i, 'optional'), 'retired' => Form::bool($i, 'retired')];
        }

        $actions->saveItems(self::user($request), $items);

        return response()->json(['items' => self::itemList()]);
    }

    /**
     * Saves the school shop price list.
     *
     * @response array{saved: bool}
     */
    public function saveShop(Request $request, Access $access, TermFeeActions $actions): JsonResponse
    {
        $access->check(self::user($request), FeesPermission::Manage);
        $request->validate(['items' => ['present', 'array', 'max:200'], 'items.*.id' => ['nullable', 'uuid'], 'items.*.name' => ['required', 'string', 'max:80'],
            'items.*.size' => ['nullable', 'string', 'max:30'], 'items.*.price' => ['required', 'integer', 'min:1'], 'items.*.retired' => ['required', 'boolean']]);
        $items = [];

        foreach (Form::rows($request, 'items') as $i) {
            $items[] = ['id' => Form::maybe($i, 'id'), 'name' => Form::text($i, 'name'), 'size' => Form::maybe($i, 'size'), 'price' => Form::int($i, 'price'), 'retired' => Form::bool($i, 'retired')];
        }

        $actions->saveShop(self::user($request), $items);

        return response()->json(['saved' => true]);
    }

    /**
     * The fees desk: this term's totals, who is behind their plan, and today's takings.
     *
     * @response array{today: string, terms: list<array{division: string, name: string, year: string}>, billed: int, billed_learners: int, collected: int, due_unpaid: int, behind_count: int, paid_in_full: int, credit: int, credit_learners: int, behind: list<array{learner_id: string, name: string, class: string, due_now: int, since: string, owed_on: string, days: int}>, takings: list<array{method: string, label: string, receipts: int, amount: int}>, cashiers: list<array{name: string, receipts: int}>, cancelled_today: int, expected_cash: int, closed: array{expected: int, counted: int, at: string}|null}
     */
    public function desk(Request $request, FeesViews $views): JsonResponse
    {
        return response()->json($views->desk(self::user($request)));
    }

    /**
     * Closes a day: the cash counted against the cash receipts.
     *
     * @response array{difference: int}
     */
    public function close(Request $request, string $day, Access $access, DeskActions $desk): JsonResponse
    {
        $access->check(self::user($request), FeesPermission::Collect);
        $request->validate(['counted' => ['required', 'integer', 'min:0'], 'note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['difference' => $desk->closeDay(self::user($request), $day, $request->integer('counted'), $request->string('note')->toString())]);
    }

    /**
     * Reminds the families of learners behind their plan, by text message.
     *
     * @response array{sent: int}
     */
    public function remind(Request $request, Access $access, DeskActions $desk): JsonResponse
    {
        $access->check(self::user($request), FeesPermission::Collect);
        $request->validate(['learner_ids' => ['required', 'array', 'min:1', 'max:2000'], 'learner_ids.*' => ['uuid']]);

        return response()->json(['sent' => $desk->remind(self::user($request), Form::strings($request, 'learner_ids'))]);
    }

    /** What was received and billed between two days, as a spreadsheet for the school's accountant. */
    public function export(Request $request, Access $access, Audit $audit): StreamedResponse
    {
        $who = self::user($request);
        $access->check($who, FeesPermission::Manage);
        $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $audit->record(FeesAudit::Exported, actor: $audit->actorFor($who), meta: ['from' => $from, 'to' => $to]);

        return new StreamedResponse(function () use ($from, $to): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fputcsv($out, ['Kind', 'Number', 'Date', 'Learner ID', 'Detail', 'Method', 'Reference', 'Paid by', 'Amount (MWK)', 'Cancelled by']);

            foreach (DB::table('fee_payments as p')->join('fee_payment_shares as s', 's.payment_id', '=', 'p.id')->leftJoin('fee_payment_voids as v', 'v.payment_id', '=', 'p.id')
                ->whereBetween('p.received_on', [$from, $to])->orderBy('p.number')
                ->get(['p.number', 'p.received_on', 's.learner_id', 'p.method', 'p.reference', 'p.payer_name', 's.amount_minor', 'v.number as void']) as $r) {
                fputcsv($out, ['Payment', Row::text($r, 'number'), Row::text($r, 'received_on'), Row::text($r, 'learner_id'), '', PaymentMethod::from(Row::text($r, 'method'))->label(),
                    Row::maybeText($r, 'reference'), Row::text($r, 'payer_name'), number_format(Row::int($r, 'amount_minor') / 100, 2, '.', ''), Row::maybeText($r, 'void')]);
            }

            foreach (DB::table('fee_charges')->whereBetween('issued_on', [$from, $to])->orderBy('number')->get(['number', 'issued_on', 'learner_id', 'label', 'total_minor']) as $c) {
                fputcsv($out, ['Bill', Row::text($c, 'number'), Row::text($c, 'issued_on'), Row::text($c, 'learner_id'), Row::text($c, 'label'), '', '', '', number_format(Row::int($c, 'total_minor') / 100, 2, '.', ''), '']);
            }

            foreach (DB::table('fee_adjustments')->whereBetween('made_on', [$from, $to])->orderBy('number')->get(['number', 'made_on', 'learner_id', 'kind', 'reason', 'amount_minor']) as $a) {
                fputcsv($out, ['Adjustment', Row::text($a, 'number'), Row::text($a, 'made_on'), Row::text($a, 'learner_id'), Row::text($a, 'kind').': '.Row::text($a, 'reason'), '', '', '', number_format(-Row::int($a, 'amount_minor') / 100, 2, '.', ''), '']);
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="fees-'.$from.'-to-'.$to.'.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return list<array{id: string, name: string, optional: bool, retired: bool}> */
    private static function itemList(): array
    {
        $out = [];

        foreach (DB::table('fee_items')->orderBy('position')->get(['id', 'name', 'optional', 'retired_at']) as $i) {
            $out[] = ['id' => Row::text($i, 'id'), 'name' => Row::text($i, 'name'), 'optional' => (bool) $i->optional, 'retired' => $i->retired_at !== null];
        }

        return $out;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
