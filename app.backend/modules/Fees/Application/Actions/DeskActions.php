<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Fees\Application\FeesReach;
use Acadly\Modules\Fees\Application\Ledger;
use Acadly\Modules\Fees\Contracts\FeesAudit;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Domain\PaymentMethod;
use Acadly\Modules\Fees\Notifications\FeeReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    ) {}

    /** The cash counted against the cash receipts of the day. A closed day takes no more payments. */
    public function closeDay(User $by, string $day, int $counted, ?string $note): int
    {
        $this->access->check($by, FeesPermission::Collect);

        if ($day > $this->clock->today()) {
            throw ValidationException::withMessages(['day' => ['A day is closed once it has come.']]);
        }

        return DB::transaction(function () use ($by, $day, $counted, $note): int {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['fees:day:'.$day]);

            if ($this->reach->dayClosed($day)) {
                throw ValidationException::withMessages(['day' => ['The day is already closed.']]);
            }

            $expected = self::cashOn($day);
            DB::table('fee_days')->insert(['day' => $day, 'expected_cash_minor' => $expected, 'counted_cash_minor' => $counted,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 1000) : null, 'closed_by' => $by->id, 'closed_at' => $this->clock->now()]);
            $this->audit->record(FeesAudit::DayClosed, actor: $this->audit->actorFor($by),
                meta: ['day' => $day, 'expected_minor' => $expected, 'counted_minor' => $counted, 'difference_minor' => $counted - $expected]);

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

    /** The cash received on a day, without cancelled receipts. */
    public static function cashOn(string $day): int
    {
        return (int) DB::table('fee_payments as p')->leftJoin('fee_payment_voids as v', 'v.payment_id', '=', 'p.id')->whereNull('v.payment_id')
            ->where('p.received_on', $day)->where('p.method', PaymentMethod::Cash->value)->sum('p.amount_minor');
    }
}
