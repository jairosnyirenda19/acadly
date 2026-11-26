<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Grid\Grids;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Application\Actions\DeskActions;
use Acadly\Modules\Fees\Application\Actions\FamilyFeeActions;
use Acadly\Modules\Fees\Application\Actions\OfficeActions;
use Acadly\Modules\Fees\Application\Actions\TermFeeActions;
use Acadly\Modules\Fees\Application\FamilyFees;
use Acadly\Modules\Fees\Application\FeeOffices;
use Acadly\Modules\Fees\Application\FeesReach;
use Acadly\Modules\Fees\Application\FeesViews;
use Acadly\Modules\Fees\Application\TermFees;
use Acadly\Modules\Fees\Contracts\FeesAudit;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Contracts\StudentProgrammes;
use Acadly\Modules\Fees\Domain\PaymentMethod;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Query\Builder;
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
        $who = self::user($request);
        $access->check($who, FeesPermission::Manage);
        $out = [];

        foreach ($terms->terms() as $t) {
            // Only the divisions of the place worked in (D-099).
            if (! $access->allows($who, FeesPermission::Manage, ScopeRef::of(ScopeType::Division, $t['division_id']))) {
                continue;
            }
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
     * @response array{term: array{period_id: string, division_id: string, division: string, year: string, name: string, starts_on: string, ends_on: string}, items: list<array{id: string, name: string, optional: bool, retired: bool}>, levels: list<array{code: string, label: string}>, amounts: list<array{item_id: string, level_code: string, amount: int}>, plan: list<array{label: string, due_on: string, share_bp: int}>, billed: bool, billed_learners: int, to_bill: array{learners: int, total: int}, programmes: list<array{id: string, code: string, name: string}>, programme_amounts: list<array{item_id: string, programme_id: string, mode: string, level_code: string, amount: int}>}
     */
    public function term(Request $request, string $period, string $division, Access $access, TermFees $terms): JsonResponse
    {
        $access->check(self::user($request), FeesPermission::Manage, ScopeRef::of(ScopeType::Division, $division));
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

        $byProgramme = [];

        foreach ($schedule === null ? [] : $terms->programmeAmounts($schedule) as $key => $items) {
            [$programme, $mode] = explode('|', $key);

            foreach ($items as $item => $levels) {
                foreach ($levels as $level => $amount) {
                    $byProgramme[] = ['item_id' => $item, 'programme_id' => $programme, 'mode' => $mode, 'level_code' => $level, 'amount' => $amount];
                }
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
            // A college's programmes (D-116); none for a school.
            'programmes' => app()->bound(StudentProgrammes::class) ? app(StudentProgrammes::class)->programmes($division) : [],
            'programme_amounts' => $byProgramme,
        ]);
    }

    /**
     * Saves the fees of a term. Refused once the term is billed.
     *
     * @response array{saved: bool}
     */
    public function saveTerm(Request $request, string $period, string $division, Access $access, TermFeeActions $actions): JsonResponse
    {
        $access->check(self::user($request), FeesPermission::Manage, ScopeRef::of(ScopeType::Division, $division));
        $request->validate([
            'amounts' => ['present', 'array'],
            'amounts.*.item_id' => ['required', 'uuid'],
            'amounts.*.level_code' => ['required', 'string', 'max:20'],
            'amounts.*.amount' => ['required', 'integer', 'min:0'],
            'plan' => ['required', 'array', 'min:1', 'max:6'],
            'plan.*.label' => ['required', 'string', 'max:60'],
            'plan.*.due_on' => ['required', 'date_format:Y-m-d'],
            'plan.*.share_bp' => ['required', 'integer', 'min:1', 'max:10000'],
            'programme_amounts' => ['sometimes', 'array'],
            'programme_amounts.*.item_id' => ['required', 'uuid'],
            'programme_amounts.*.programme_id' => ['required', 'uuid'],
            'programme_amounts.*.mode' => ['required', 'string', 'max:12'],
            'programme_amounts.*.level_code' => ['required', 'string', 'max:20'],
            'programme_amounts.*.amount' => ['required', 'integer', 'min:0'],
        ]);

        $amounts = [];
        $plan = [];

        foreach (Form::rows($request, 'amounts') as $a) {
            $amounts[] = ['item_id' => Form::text($a, 'item_id'), 'level_code' => Form::text($a, 'level_code'), 'amount' => Form::int($a, 'amount')];
        }

        foreach (Form::rows($request, 'plan') as $p) {
            $plan[] = ['label' => Form::text($p, 'label'), 'due_on' => Form::text($p, 'due_on'), 'share_bp' => Form::int($p, 'share_bp')];
        }

        $byProgramme = [];

        foreach (Form::rows($request, 'programme_amounts') as $a) {
            $byProgramme[] = ['item_id' => Form::text($a, 'item_id'), 'programme_id' => Form::text($a, 'programme_id'), 'mode' => Form::text($a, 'mode'),
                'level_code' => Form::text($a, 'level_code'), 'amount' => Form::int($a, 'amount')];
        }

        $actions->saveTerm(self::user($request), $period, $division, $amounts, $plan, $byProgramme);

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
     * @response array{today: string, terms: list<array{division: string, name: string, year: string}>, billed: int, billed_learners: int, collected: int, due_unpaid: int, behind_count: int, paid_in_full: int, credit: int, credit_learners: int, behind: list<array{learner_id: string, name: string, class: string, due_now: int, since: string, owed_on: string, days: int}>, takings: list<array{method: string, label: string, receipts: int, amount: int}>, cashiers: list<array{name: string, receipts: int}>, cancelled_today: int, expected_cash: int, office: string|null, staffing: array{named: bool, works: bool}|null, closed: array{expected: int, counted: int, at: string}|null}
     */
    public function desk(Request $request, FeesViews $views): JsonResponse
    {
        return response()->json($views->desk(self::user($request)));
    }

    /**
     * The payments ledger as a data grid: `?page=&per_page=&sort=&dir=&q=&filter[received]=from..to&filter[method]=cash&filter[state]=cancelled`.
     *
     * @response array{rows: list<array{id: string, receipt: string, received_on: string, learners: string, payer: string, method: string, method_label: string, reference: string|null, amount: int, received_by: string|null, cancelled: bool, void_number: string|null}>, total: int, page: int, per_page: int, pages: int, sort: string, dir: 'asc'|'desc', q: string, filters: array<string, list<string>>, columns: list<array{key: string, label: string, sortable: bool, shown: bool, always: bool}>, filter_options: list<array{key: string, label: string, kind: 'choice'|'dates', options: list<array{value: string, label: string, count: int}>}>, views: list<array{id: string, name: string, state: array<string, mixed>}>, bulk: list<array{key: string, label: string}>, can_export: bool}
     */
    public function payments(Request $request, Grids $grids): JsonResponse
    {
        return response()->json($grids->page(self::user($request), $grids->grid('fee-payments'), $request->query()));
    }

    /**
     * The learners behind their plan as a data grid: `?page=&per_page=&sort=&dir=&q=&filter[class]=…&filter[overdue]=month&filter[since]=from..to`.
     *
     * @response array{rows: list<array{id: string, name: string, class_id: string|null, class: string, owed_on: string, due_now: int, since: string, days: int}>, total: int, page: int, per_page: int, pages: int, sort: string, dir: 'asc'|'desc', q: string, filters: array<string, list<string>>, columns: list<array{key: string, label: string, sortable: bool, shown: bool, always: bool}>, filter_options: list<array{key: string, label: string, kind: 'choice'|'dates', options: list<array{value: string, label: string, count: int}>}>, views: list<array{id: string, name: string, state: array<string, mixed>}>, bulk: list<array{key: string, label: string}>, can_export: bool}
     */
    public function behind(Request $request, Grids $grids): JsonResponse
    {
        return response()->json($grids->page(self::user($request), $grids->grid('fee-behind'), $request->query()));
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
    public function export(Request $request, Access $access, Audit $audit, FeesReach $reach): StreamedResponse
    {
        $who = self::user($request);
        $access->check($who, FeesPermission::Manage);
        $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $audit->record(FeesAudit::Exported, actor: $audit->actorFor($who), meta: ['from' => $from, 'to' => $to]);

        return new StreamedResponse(function () use ($from, $to, $reach, $who): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fputcsv($out, ['Kind', 'Number', 'Date', 'Learner ID', 'Detail', 'Method', 'Reference', 'Paid by', 'Amount (MWK)', 'Cancelled by']);

            foreach ($reach->keepLearners(DB::table('fee_payments as p')->join('fee_payment_shares as s', 's.payment_id', '=', 'p.id'), 's.learner_id', $who)->leftJoin('fee_payment_voids as v', 'v.payment_id', '=', 'p.id')
                ->whereBetween('p.received_on', [$from, $to])->orderBy('p.number')
                ->get(['p.number', 'p.received_on', 's.learner_id', 'p.method', 'p.reference', 'p.payer_name', 's.amount_minor', 'v.number as void']) as $r) {
                fputcsv($out, ['Payment', Row::text($r, 'number'), Row::text($r, 'received_on'), Row::text($r, 'learner_id'), '', PaymentMethod::from(Row::text($r, 'method'))->label(),
                    Row::maybeText($r, 'reference'), Row::text($r, 'payer_name'), number_format(Row::int($r, 'amount_minor') / 100, 2, '.', ''), Row::maybeText($r, 'void')]);
            }

            foreach ($reach->keepLearners(DB::table('fee_charges'), 'learner_id', $who)->whereBetween('issued_on', [$from, $to])->orderBy('number')->get(['number', 'issued_on', 'learner_id', 'label', 'total_minor']) as $c) {
                fputcsv($out, ['Bill', Row::text($c, 'number'), Row::text($c, 'issued_on'), Row::text($c, 'learner_id'), Row::text($c, 'label'), '', '', '', number_format(Row::int($c, 'total_minor') / 100, 2, '.', ''), '']);
            }

            foreach ($reach->keepLearners(DB::table('fee_adjustments'), 'learner_id', $who)->whereBetween('made_on', [$from, $to])->orderBy('number')->get(['number', 'made_on', 'learner_id', 'kind', 'reason', 'amount_minor']) as $a) {
                fputcsv($out, ['Adjustment', Row::text($a, 'number'), Row::text($a, 'made_on'), Row::text($a, 'learner_id'), Row::text($a, 'kind').': '.Row::text($a, 'reason'), '', '', '', number_format(-Row::int($a, 'amount_minor') / 100, 2, '.', ''), '']);
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="fees-'.$from.'-to-'.$to.'.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Fees in the parent portal (D-108): whether the confirmed parents of each child see the child's fees, and how to pay.
     *
     * @response array{show_to_families: bool, how_to_pay: string, updated_at: string|null, max: int}
     */
    public function portal(Request $request, Access $access, FamilyFees $fees): JsonResponse
    {
        $access->check(self::user($request), FeesPermission::Manage);

        return response()->json([...$fees->settings(), 'max' => FamilyFeeActions::HOW_TO_PAY_MAX]);
    }

    /**
     * Saves fees in the parent portal. How to pay: up to 600 characters, no links.
     *
     * @response array{show_to_families: bool, how_to_pay: string, updated_at: string|null, max: int}
     */
    public function savePortal(Request $request, Access $access, FamilyFeeActions $actions, FamilyFees $fees): JsonResponse
    {
        $access->check(self::user($request), FeesPermission::Manage);
        $request->validate(['show_to_families' => ['required', 'boolean'], 'how_to_pay' => ['present', 'nullable', 'string', 'max:'.FamilyFeeActions::HOW_TO_PAY_MAX]]);
        $actions->saveSettings(self::user($request), $request->boolean('show_to_families'), $request->string('how_to_pay')->toString());

        return response()->json([...$fees->settings(), 'max' => FamilyFeeActions::HOW_TO_PAY_MAX]);
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

    /**
     * The finance offices (D-099): one for the whole school, or the levels at each campus split among named offices.
     *
     * @response array{offices: list<array{id: string, name: string, offerings: list<string>, own: bool, staff: list<string>}>, offerings: list<array{id: string, division: string, campus: string}>, people: list<array{id: string, name: string}>}
     */
    public function offices(Request $request, Access $access, FeeOffices $offices): JsonResponse
    {
        $access->check(self::user($request), FeesPermission::Manage);
        $out = [];
        foreach (DB::table('institution_offerings as o')->join('institution_divisions as d', 'd.id', '=', 'o.division_id')->join('institution_campuses as c', 'c.id', '=', 'o.campus_id')
            ->whereNull('o.ended_at')->orderBy('d.position')->orderBy('c.position')->get(['o.id', 'd.name as division', 'c.name as campus']) as $o) {
            $out[] = ['id' => Row::text($o, 'id'), 'division' => Row::text($o, 'division'), 'campus' => Row::text($o, 'campus')];
        }

        // Who may be named: everyone holding a fees role somewhere in the school.
        $people = [];
        foreach (DB::table('access_role_assignments as a')->join('users as u', 'u.id', '=', 'a.user_id')->join('access_role_permissions as p', 'p.role_id', '=', 'a.role_id')
            ->where('p.permission_key', 'like', 'fees.%')->where('a.starts_at', '<=', now())->where(fn (Builder $q): Builder => $q->whereNull('a.ends_at')->orWhere('a.ends_at', '>', now()))
            ->distinct()->orderBy('u.name')->get(['u.id', 'u.name']) as $p) {
            $people[] = ['id' => Row::text($p, 'id'), 'name' => Row::text($p, 'name')];
        }

        return response()->json(['offices' => $offices->offices(), 'offerings' => $out, 'people' => $people]);
    }

    /**
     * Saves the named finance offices. None: one office for the whole school.
     *
     * @response array{saved: bool}
     */
    public function saveOffices(Request $request, Access $access, OfficeActions $actions): JsonResponse
    {
        $who = self::user($request);
        $access->check($who, FeesPermission::Manage);
        $request->validate(['offices' => ['present', 'array', 'max:20'], 'offices.*.id' => ['nullable', 'uuid'], 'offices.*.name' => ['required', 'string', 'max:80'],
            'offices.*.offerings' => ['present', 'array'], 'offices.*.offerings.*' => ['uuid'],
            'offices.*.staff' => ['sometimes', 'array', 'max:50'], 'offices.*.staff.*' => ['uuid'], 'own_staff' => ['sometimes', 'array', 'max:50'], 'own_staff.*' => ['uuid']]);
        $list = [];
        foreach ((array) $request->input('offices') as $o) {
            $o = is_array($o) ? $o : [];
            $list[] = ['id' => is_string($o['id'] ?? null) ? $o['id'] : null, 'name' => is_string($o['name'] ?? null) ? $o['name'] : '',
                'offerings' => array_values(array_filter(is_array($o['offerings'] ?? null) ? $o['offerings'] : [], 'is_string')),
                'staff' => array_values(array_filter(is_array($o['staff'] ?? null) ? $o['staff'] : [], 'is_string'))];
        }
        $own = $request->input('own_staff');
        $actions->save($who, $list, array_values(array_filter(is_array($own) ? $own : [], 'is_string')));

        return response()->json(['saved' => true]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
