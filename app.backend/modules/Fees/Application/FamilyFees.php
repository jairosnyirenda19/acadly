<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\Guardianship;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Phone;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Fees in the parent portal (D-108). A confirmed parent sees each child's fees
 * for the term: what is billed, paid and left, each instalment, the payments
 * with the child's own part, and how to pay. Shown only while the school shows
 * fees to families and the Fees module is on where the child is placed.
 * Anything else is "not found", so a refusal never says a child or a fee exists.
 *
 * @phpstan-type FamilyInstalment array{label: string, charge: string, due_on: string, amount: int, paid: int, left: int, state: 'paid'|'overdue'|'due'|'not_due', this_term: bool}
 * @phpstan-type FamilyPayment array{id: string, number: string, received_on: string, method: string, amount: int, cancelled: bool, shared: bool, receipt: bool, this_term: bool, period: string|null}
 * @phpstan-type NextDue array{on: string, amount: int, overdue: bool}
 */
final class FamilyFees
{
    public function __construct(
        private readonly Guardianship $guardianship,
        private readonly Ledger $ledger,
        private readonly TermFees $terms,
        private readonly EnrolmentDirectory $directory,
        private readonly ModuleActivation $activation,
        private readonly SchoolProfile $school,
        private readonly Clock $clock,
    ) {}

    /** @return array{show_to_families: bool, how_to_pay: string, updated_at: string|null} */
    public function settings(): array
    {
        $row = DB::table('fees_portal_settings')->where('id', 1)->first(['show_to_families', 'how_to_pay', 'updated_at']);

        return [
            'show_to_families' => $row === null || (bool) $row->show_to_families,
            'how_to_pay' => $row === null ? '' : (Row::maybeText($row, 'how_to_pay') ?? ''),
            'updated_at' => $row === null ? null : Row::maybeText($row, 'updated_at'),
        ];
    }

    /**
     * Each child of the family with fees shown, and the family's total left and next due.
     *
     * @return array{children: list<array{id: string, billed: int, left: int, next_due: NextDue|null}>, left: int, next: array{on: string, amount: int, overdue: bool, child_id: string, first_name: string}|null}
     */
    public function family(User $parent): array
    {
        $this->open();
        $children = [];
        $left = 0;
        $next = null;

        foreach ($this->guardianship->children($parent) as $child) {
            if (! $this->shownFor($child['class_id'])) {
                continue;
            }

            $fees = $this->feesOf($child['id']);

            if ($fees['instalments'] === []) {
                continue;
            }

            $children[] = ['id' => $child['id'], 'billed' => $fees['billed'], 'left' => $fees['left'], 'next_due' => $fees['next_due']];
            $left += $fees['left'];
            $due = $fees['next_due'];

            if ($due !== null && ($next === null || $due['on'] < $next['on'])) {
                $next = [...$due, 'child_id' => $child['id'], 'first_name' => $child['first_name']];
            }
        }

        return ['children' => $children, 'left' => $left, 'next' => $next];
    }

    /**
     * One child's fees, as the parent sees them.
     *
     * @return array{child: array{id: string, first_name: string, name: string, class: string}, term: array{name: string, year: string}|null, billed: int, paid: int, left: int, credit: int, next_due: NextDue|null, instalments: list<FamilyInstalment>, payments: list<FamilyPayment>, how_to_pay: string, school_phone: string|null}
     */
    public function child(User $parent, string $learnerId): array
    {
        $child = $this->childOf($parent, $learnerId);
        $fees = $this->feesOf($learnerId);
        $family = array_column($this->guardianship->children($parent), 'id');

        return [
            'child' => ['id' => $child['id'], 'first_name' => $child['first_name'], 'name' => $child['name'], 'class' => $child['class']],
            'term' => $fees['term'] === null ? null : ['name' => $fees['term']['name'], 'year' => $fees['term']['year']],
            'billed' => $fees['billed'],
            'paid' => $fees['paid'],
            'left' => $fees['left'],
            'credit' => $fees['credit'],
            'next_due' => $fees['next_due'],
            'instalments' => $fees['instalments'],
            'payments' => $this->paymentsOf($learnerId, $family, $fees['term'], $fees['division']),
            'how_to_pay' => $this->settings()['how_to_pay'],
            'school_phone' => Phone::forPeople($this->school->phone()),
        ];
    }

    /**
     * The child the parent sees, with fees shown for them: or not found.
     *
     * @return array{id: string, first_name: string, name: string, class_id: string|null, class: string}
     */
    public function childOf(User $parent, string $learnerId): array
    {
        $this->open();
        $child = $this->guardianship->child($parent, $learnerId);

        if ($child === null || ! $this->shownFor($child['class_id'])) {
            throw new NotFoundHttpException;
        }

        return $child;
    }

    /**
     * A receipt the parent may open: it includes this child, and every child it
     * names is one the parent sees. Otherwise not found.
     */
    public function receiptFor(User $parent, string $learnerId, string $paymentId): string
    {
        $this->childOf($parent, $learnerId);
        $learners = array_values(array_filter(DB::table('fee_payment_shares')->where('payment_id', $paymentId)->pluck('learner_id')->all(), 'is_string'));
        $family = array_column($this->guardianship->children($parent), 'id');

        if (! in_array($learnerId, $learners, true) || array_diff($learners, $family) !== []) {
            throw new NotFoundHttpException;
        }

        $number = DB::table('fee_payments')->where('id', $paymentId)->value('number');

        return is_string($number) ? $number : throw new NotFoundHttpException;
    }

    /** The school shows fees to families. */
    private function open(): void
    {
        if (! $this->settings()['show_to_families']) {
            throw new NotFoundHttpException;
        }
    }

    /** Fees is on where the child is placed now. */
    private function shownFor(?string $classId): bool
    {
        return $classId !== null && $this->activation->isActive('fees', ScopeRef::of(ScopeType::ClassGroup, $classId));
    }

    /**
     * The term's instalments, and any other still owed, with their totals.
     *
     * @return array{term: array{period_id: string, division_id: string, division: string, year: string, year_id: string, name: string, starts_on: string, ends_on: string}|null, division: string|null, billed: int, paid: int, left: int, credit: int, next_due: NextDue|null, instalments: list<FamilyInstalment>}
     */
    private function feesOf(string $learnerId): array
    {
        $today = $this->clock->today();
        $standing = $this->ledger->standing($learnerId);
        $place = $this->directory->placeOf($learnerId);
        $division = $place['division_id'] ?? null;
        $term = $division !== null ? $this->terms->currentTerm($division) : null;
        $ofTerm = [];

        if ($term !== null) {
            $schedule = $this->terms->scheduleId($term['period_id'], $term['division_id']);
            $charges = DB::table('fee_charges')->where('learner_id', $learnerId)
                ->where(fn (Builder $q): Builder => $q->when($schedule !== null, fn (Builder $q): Builder => $q->orWhere('schedule_id', $schedule))
                    ->orWhere(fn (Builder $q): Builder => $q->whereNull('schedule_id')->whereBetween('issued_on', [$term['starts_on'], $term['ends_on']])))
                ->pluck('id')->all();
            $ofTerm = array_flip(array_filter($charges, 'is_string'));
        }

        $list = [];
        $next = null;

        foreach ($standing['instalments'] as $i) {
            $left = max(0, $i['amount'] - $i['covered']);
            $thisTerm = isset($ofTerm[$i['charge_id']]);

            if (! $thisTerm && $left === 0) {
                continue;
            }

            $state = $left === 0 ? 'paid' : ($i['due_on'] < $today ? 'overdue' : ($i['covered'] > 0 || $i['due_on'] === $today ? 'due' : 'not_due'));
            $list[] = ['label' => $i['label'], 'charge' => $i['charge'], 'due_on' => $i['due_on'], 'amount' => $i['amount'], 'paid' => $i['covered'], 'left' => $left,
                'state' => $state, 'this_term' => $thisTerm];

            if ($left > 0 && ($next === null || $i['due_on'] < $next['on'])) {
                $next = ['on' => $i['due_on'], 'amount' => $left, 'overdue' => $i['due_on'] < $today];
            }
        }

        return [
            'term' => $term,
            'division' => $division,
            'billed' => array_sum(array_column($list, 'amount')),
            'paid' => array_sum(array_column($list, 'paid')),
            'left' => array_sum(array_column($list, 'left')),
            'credit' => $standing['credit'],
            'next_due' => $next,
            'instalments' => $list,
        ];
    }

    /**
     * The child's payments, newest first, each with the child's own part. A receipt
     * may be opened when every child it names is one the parent sees.
     *
     * @param  list<string>  $family
     * @param  array{period_id: string, division_id: string, division: string, year: string, year_id: string, name: string, starts_on: string, ends_on: string}|null  $term
     * @return list<FamilyPayment>
     */
    private function paymentsOf(string $learnerId, array $family, ?array $term, ?string $division): array
    {
        $periods = array_values(array_filter($this->terms->terms(), fn (array $t): bool => $t['division_id'] === $division));
        $rows = DB::table('fee_payment_shares as s')->join('fee_payments as p', 'p.id', '=', 's.payment_id')->leftJoin('fee_payment_voids as v', 'v.payment_id', '=', 'p.id')
            ->where('s.learner_id', $learnerId)->orderByDesc('p.received_on')->orderByDesc('p.created_at')
            ->get(['p.id', 'p.number', 'p.received_on', 'p.method', 's.amount_minor', 'v.number as void_number']);
        $ids = array_values(array_map(fn (object $r): string => Row::text($r, 'id'), $rows->all()));
        $named = [];

        foreach (DB::table('fee_payment_shares')->whereIn('payment_id', $ids === [] ? ['00000000-0000-0000-0000-000000000000'] : $ids)->get(['payment_id', 'learner_id']) as $s) {
            $named[Row::text($s, 'payment_id')][] = Row::text($s, 'learner_id');
        }

        $out = [];

        foreach ($rows as $r) {
            $on = substr(Row::text($r, 'received_on'), 0, 10);
            $period = null;

            foreach ($periods as $t) {
                if ($t['starts_on'] <= $on && $t['ends_on'] >= $on) {
                    $period = $t['name'];
                    break;
                }
            }

            $learners = $named[Row::text($r, 'id')] ?? [];
            $out[] = [
                'id' => Row::text($r, 'id'),
                'number' => Row::text($r, 'number'),
                'received_on' => $on,
                'method' => Row::text($r, 'method'),
                'amount' => Row::int($r, 'amount_minor'),
                'cancelled' => Row::maybeText($r, 'void_number') !== null,
                'shared' => count($learners) > 1,
                'receipt' => array_diff($learners, $family) === [],
                'this_term' => $term !== null && $term['starts_on'] <= $on && $term['ends_on'] >= $on,
                'period' => $period,
            ];
        }

        return $out;
    }
}
