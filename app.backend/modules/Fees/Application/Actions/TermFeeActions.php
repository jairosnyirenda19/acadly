<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Fees\Application\Numbers;
use Acadly\Modules\Fees\Application\TermFees;
use Acadly\Modules\Fees\Contracts\FeesAudit;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Contracts\StudentProgrammes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The school's fees: items, the fees of a term, billing, and the shop price list (D-091). */
final class TermFeeActions
{
    public function __construct(
        private readonly TermFees $terms,
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * The list of fee items. An item in use is retired, not deleted.
     *
     * @param  list<array{id: string|null, name: string, optional: bool, retired: bool}>  $items
     */
    public function saveItems(User $by, array $items): void
    {
        $this->access->check($by, FeesPermission::Manage);
        $names = array_map(fn (array $i): string => mb_strtolower(trim($i['name'])), array_filter($items, fn (array $i): bool => ! $i['retired']));

        if (in_array('', $names, true) || count($names) !== count(array_unique($names))) {
            throw ValidationException::withMessages(['items' => ['Give each item a name, once.']]);
        }

        DB::transaction(function () use ($by, $items): void {
            $now = $this->clock->now();

            foreach ($items as $i => $item) {
                $row = ['name' => mb_substr(trim($item['name']), 0, 80), 'optional' => $item['optional'], 'position' => $i + 1, 'updated_at' => $now];

                if ($item['id'] === null) {
                    DB::table('fee_items')->insert([...$row, 'id' => Ids::next(), 'created_at' => $now, 'retired_at' => $item['retired'] ? $now : null]);
                } else {
                    // A retired item keeps the moment it was first retired.
                    DB::table('fee_items')->where('id', $item['id'])->update([...$row, 'retired_at' => $item['retired'] ? DB::raw('coalesce(retired_at, now())') : null]);
                }
            }

            $this->audit->record(FeesAudit::ItemsSaved, actor: $this->audit->actorFor($by), meta: ['items' => count($items)]);
        });
    }

    /**
     * The fees of one term: amounts by item and level, and the school's instalment plan.
     * Once the term is billed, its fees no longer change: a change is made for one learner.
     *
     * @param  list<array{item_id: string, level_code: string, amount: int}>  $amounts
     * @param  list<array{label: string, due_on: string, share_bp: int}>  $plan
     * @param  list<array{item_id: string, programme_id: string, mode: string, level_code: string, amount: int}>  $byProgramme  a college's amounts by programme and mode (D-116)
     */
    public function saveTerm(User $by, string $periodId, string $divisionId, array $amounts, array $plan, array $byProgramme = []): void
    {
        $this->access->check($by, FeesPermission::Manage, ScopeRef::of(ScopeType::Division, $divisionId));
        $term = $this->terms->term($periodId, $divisionId);
        $levels = array_column($this->terms->levels($divisionId), 'code');
        $items = DB::table('fee_items')->whereNull('retired_at')->pluck('id')->all();
        $errors = [];

        foreach ($amounts as $a) {
            if (! in_array($a['level_code'], $levels, true) || ! in_array($a['item_id'], $items, true) || $a['amount'] < 0) {
                $errors['amounts'] = ['Give an amount of nothing or more for each item and level.'];
            }
        }
        $programmes = $byProgramme === [] || ! app()->bound(StudentProgrammes::class) ? [] : array_column(app(StudentProgrammes::class)->programmes($divisionId), 'id');

        foreach ($byProgramme as $a) {
            if (! in_array($a['programme_id'], $programmes, true) || ! in_array($a['mode'], ['full_time', 'part_time'], true) || ! in_array($a['item_id'], $items, true)
                || ($a['level_code'] !== 'credit' && ! in_array($a['level_code'], $levels, true)) || $a['amount'] < 0) {
                $errors['programme_amounts'] = ['Give an amount of nothing or more for each programme, mode and year of study.'];
            }
        }
        if ($plan === [] || count($plan) > 6) {
            $errors['plan'] = ['Give from 1 to 6 instalments.'];
        } elseif (array_sum(array_column($plan, 'share_bp')) !== 10000) {
            $errors['plan'] = ['The instalments must add up to 100%.'];
        }
        foreach ($plan as $p) {
            if (trim($p['label']) === '' || $p['share_bp'] <= 0) {
                $errors['plan'] = ['Give each instalment a name, a date and a share.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($by, $term, $amounts, $plan, $byProgramme): void {
            $now = $this->clock->now();
            $schedule = $this->terms->scheduleId($term['period_id'], $term['division_id']);

            if ($schedule !== null && DB::table('fee_schedules')->where('id', $schedule)->lockForUpdate()->whereNotNull('billed_at')->exists()) {
                throw ValidationException::withMessages(['term' => ['The term is billed. Its fees no longer change: give a discount or agree a plan for one learner.']]);
            }

            if ($schedule === null) {
                $schedule = Ids::next();
                DB::table('fee_schedules')->insert(['id' => $schedule, 'period_id' => $term['period_id'], 'division_id' => $term['division_id'], 'created_at' => $now, 'updated_at' => $now]);
            }

            DB::table('fee_schedule_amounts')->where('schedule_id', $schedule)->delete();
            DB::table('fee_schedule_programme_amounts')->where('schedule_id', $schedule)->delete();

            foreach ($byProgramme as $a) {
                DB::table('fee_schedule_programme_amounts')->insert(['id' => Ids::next(), 'schedule_id' => $schedule, 'item_id' => $a['item_id'], 'programme_id' => $a['programme_id'],
                    'mode' => $a['mode'], 'level_code' => $a['level_code'], 'amount_minor' => $a['amount']]);
            }
            DB::table('fee_schedule_instalments')->where('schedule_id', $schedule)->delete();

            foreach ($amounts as $a) {
                DB::table('fee_schedule_amounts')->insert(['id' => Ids::next(), 'schedule_id' => $schedule, 'item_id' => $a['item_id'], 'level_code' => $a['level_code'], 'amount_minor' => $a['amount']]);
            }

            usort($plan, fn (array $a, array $b): int => $a['due_on'] <=> $b['due_on']);

            foreach ($plan as $i => $p) {
                DB::table('fee_schedule_instalments')->insert(['id' => Ids::next(), 'schedule_id' => $schedule, 'position' => $i + 1, 'label' => mb_substr(trim($p['label']), 0, 60),
                    'due_on' => $p['due_on'], 'share_bp' => $p['share_bp']]);
            }

            DB::table('fee_schedules')->where('id', $schedule)->update(['updated_at' => $now]);
            $this->audit->record(FeesAudit::TermFeesSaved, actor: $this->audit->actorFor($by), meta: ['term' => $term['name'], 'division' => $term['division'], 'year' => $term['year']]);
        });
    }

    /**
     * Bills the term to every learner placed in it who is not billed yet. It may be
     * run again during the term: it bills only the learners admitted since.
     *
     * @return array{learners: int, total: int}
     */
    public function bill(User $by, string $periodId, string $divisionId): array
    {
        $this->access->check($by, FeesPermission::Manage, ScopeRef::of(ScopeType::Division, $divisionId));
        $term = $this->terms->term($periodId, $divisionId);
        $schedule = $this->terms->scheduleId($periodId, $divisionId);

        if ($schedule === null || ! DB::table('fee_schedule_instalments')->where('schedule_id', $schedule)->exists()) {
            throw ValidationException::withMessages(['term' => ['Set the fees and the instalment plan of the term first.']]);
        }

        return DB::transaction(function () use ($by, $term, $schedule): array {
            DB::table('fee_schedules')->where('id', $schedule)->lockForUpdate()->first();
            $bills = $this->terms->bills($term);
            $now = $this->clock->now();
            $issued = max($this->clock->today(), $term['starts_on']);
            $total = 0;

            foreach ($bills as $bill) {
                $id = Ids::next();
                DB::table('fee_charges')->insert(['id' => $id, 'number' => Numbers::next(Numbers::BILL, $issued), 'learner_id' => $bill['learner_id'], 'kind' => 'term',
                    'schedule_id' => $schedule, 'label' => $term['name'].' fees', 'total_minor' => $bill['total'], 'issued_on' => $issued, 'created_by' => $by->id, 'created_at' => $now]);

                foreach ($bill['lines'] as $i => $line) {
                    DB::table('fee_charge_lines')->insert(['id' => Ids::next(), 'charge_id' => $id, 'item_id' => $line['item_id'], 'label' => $line['label'],
                        'quantity' => 1, 'amount_minor' => $line['amount'], 'position' => $i + 1]);
                }

                foreach (TermFees::split($schedule, $bill['total']) as $part) {
                    DB::table('fee_instalments')->insert(['id' => Ids::next(), 'charge_id' => $id, 'position' => $part['position'], 'label' => $part['label'], 'due_on' => $part['due_on'],
                        'amount_minor' => $part['amount'], 'plan' => 'school', 'created_by' => $by->id, 'created_at' => $now]);
                }

                $total += $bill['total'];
            }

            DB::table('fee_schedules')->where('id', $schedule)->whereNull('billed_at')->update(['billed_at' => $now, 'billed_by' => $by->id, 'updated_at' => $now]);
            $this->audit->record(FeesAudit::TermBilled, actor: $this->audit->actorFor($by),
                meta: ['term' => $term['name'], 'division' => $term['division'], 'year' => $term['year'], 'learners' => count($bills), 'total_minor' => $total]);

            return ['learners' => count($bills), 'total' => $total];
        });
    }

    /**
     * The school shop price list. An item sold before is retired, not deleted.
     *
     * @param  list<array{id: string|null, name: string, size: string|null, price: int, retired: bool}>  $items
     */
    public function saveShop(User $by, array $items): void
    {
        $this->access->check($by, FeesPermission::Manage);

        foreach ($items as $item) {
            if (trim($item['name']) === '' || $item['price'] <= 0) {
                throw ValidationException::withMessages(['items' => ['Give each item a name and a price.']]);
            }
        }

        DB::transaction(function () use ($by, $items): void {
            $now = $this->clock->now();

            foreach ($items as $i => $item) {
                $size = $item['size'] !== null && trim($item['size']) !== '' ? mb_substr(trim($item['size']), 0, 30) : null;
                $row = ['name' => mb_substr(trim($item['name']), 0, 80), 'size' => $size, 'price_minor' => $item['price'], 'position' => $i + 1,
                    'retired_at' => $item['retired'] ? $now : null, 'updated_at' => $now];

                $item['id'] === null
                    ? DB::table('fee_shop_items')->insert([...$row, 'id' => Ids::next(), 'created_at' => $now])
                    : DB::table('fee_shop_items')->where('id', $item['id'])->update($row);
            }

            $this->audit->record(FeesAudit::ShopListSaved, actor: $this->audit->actorFor($by), meta: ['items' => count($items)]);
        });
    }
}
