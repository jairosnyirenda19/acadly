<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Fees\Application\FeeOffices;
use Acadly\Modules\Fees\Application\FeesReach;
use Acadly\Modules\Fees\Application\Ledger;
use Acadly\Modules\Fees\Contracts\FeesAudit;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Domain\PaymentMethod;
use Acadly\Modules\Fees\Notifications\FeeReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The fees desk: closing a day, and reminding families (D-091). */
final class DeskActions
{
    public function __construct(
        private readonly Access $access,
        private readonly FeesReach $reach,
        private readonly Ledger $ledger,
        private readonly Notifier $notifier,
        private readonly Audit $audit,
        private readonly Clock $clock,
        private readonly FeeOffices $offices,
    ) {}

    /** The cash counted against the cash receipts of the day. A closed day takes no more payments. */
    public function closeDay(User $by, string $day, int $counted, ?string $note): int
    {
        $this->access->check($by, FeesPermission::Collect);
        // A cash day belongs to a finance office (D-099): closed from the office, or a place one office serves.
        $office = $this->offices->current() ?? throw ValidationException::withMessages(['day' => ['This place is served by more than one finance office. Close the day from the office.']]);

        if ($day > $this->clock->today()) {
            throw ValidationException::withMessages(['day' => ['A day is closed once it has come.']]);
        }
        if (! FeeOffices::works($by, $office)) {
            throw ValidationException::withMessages(['day' => ['You are not named to work in this finance office.']]);
        }

        return DB::transaction(function () use ($by, $day, $counted, $note, $office): int {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['fees:day:'.$office.':'.$day]);

            if ($this->reach->dayClosed($day, $office)) {
                throw ValidationException::withMessages(['day' => ['The day is already closed.']]);
            }

            $expected = self::cashOn($day, $this->reach, $by);
            DB::table('fee_days')->insert(['day' => $day, 'office_id' => $office, 'expected_cash_minor' => $expected, 'counted_cash_minor' => $counted,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 1000) : null, 'closed_by' => $by->id, 'closed_at' => $this->clock->now()]);
            $this->audit->record(FeesAudit::DayClosed, actor: $this->audit->actorFor($by),
                meta: ['day' => $day, 'office' => $office, 'expected_minor' => $expected, 'counted_minor' => $counted, 'difference_minor' => $counted - $expected]);

            return $counted - $expected;
        });
    }

    /**
     * A reminder to the families of learners behind their plan: the amount due and its date.
     *
     * @param  list<string>  $learnerIds
     * @return int reminders sent
     */
    public function remind(User $by, array $learnerIds): int
    {
        $this->access->check($by, FeesPermission::Collect);
        $today = $this->clock->today();
        $sent = 0;

        // A learner outside the place worked in is not found (D-099), before anything is sent.
        foreach ($learnerIds as $learner) {
            if (! $this->reach->may($by, FeesPermission::Collect, $learner) && ! $this->reach->may($by, FeesPermission::View, $learner)) {
                throw new NotFoundHttpException;
            }
        }

        foreach ($this->ledger->standings($learnerIds) as $learner => $standing) {
            $place = $this->reach->place($learner);

            if ($place === null || $standing['due_now'] <= 0 || $standing['overdue_since'] === null || ! $this->reach->may($by, FeesPermission::Collect, $learner)) {
                continue;
            }

            $this->notifier->send(new FeeReminder($learner, $place['class_id'], $standing['due_now'], $standing['overdue_since'], $today), $by);
            $sent++;
        }

        $this->audit->record(FeesAudit::RemindersSent, actor: $this->audit->actorFor($by), meta: ['sent' => $sent]);

        return $sent;
    }

    /** The cash received on a day, without cancelled receipts: for the learners of the place worked in, when a viewer is given. */
    public static function cashOn(string $day, ?FeesReach $reach = null, ?User $viewer = null): int
    {
        $q = DB::table('fee_payments as p');
        if ($reach !== null && $viewer !== null) {
            $q = $reach->keepPayments($q, $viewer);
        }

        return (int) $q->leftJoin('fee_payment_voids as v', 'v.payment_id', '=', 'p.id')->whereNull('v.payment_id')
            ->where('p.received_on', $day)->where('p.method', PaymentMethod::Cash->value)->sum('p.amount_minor');
    }
}
