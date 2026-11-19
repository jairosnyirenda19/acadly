<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Documents\Pdf\DocumentChecks;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Application\FeeDocuments;
use Acadly\Modules\Fees\Application\FeesReach;
use Acadly\Modules\Fees\Application\Ledger;
use Acadly\Modules\Fees\Application\Numbers;
use Acadly\Modules\Fees\Contracts\FeesAudit;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Domain\PaymentMethod;
use Acadly\Modules\Fees\Notifications\PaymentReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Money received and its receipt (D-091). A payment is kept once and never
 * changed. One payment may cover several children of a family: each child's
 * share is its own line on that child's statement. A mistake is cancelled
 * with a reason, and the payment recorded again.
 *
 * @phpstan-type ShareInput array{learner_id: string, amount: int, charge_id?: string|null}
 */
final class PaymentActions
{
    public function __construct(
        private readonly FeesReach $reach,
        private readonly Ledger $ledger,
        private readonly Audit $audit,
        private readonly Notifier $notifier,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<ShareInput>  $shares
     * @return string the payment's id
     */
    public function record(User $by, PaymentMethod $method, ?string $reference, string $receivedOn, string $payerName, ?string $payerPhone,
        int $amount, array $shares, bool $tell = true): string
    {
        $reference = $reference !== null && trim($reference) !== '' ? mb_substr(trim($reference), 0, 80) : null;
        $payerName = trim($payerName);
        $errors = [];

        if ($amount <= 0) {
            $errors['amount'] = ['Give the amount received.'];
        }
        if ($payerName === '') {
            $errors['payer_name'] = ['Give the name of the person who paid.'];
        }
        if ($method->needsReference() && $reference === null) {
            $errors['reference'] = ['Give the '.$method->label().' reference, so the payment can be checked.'];
        }
        if ($receivedOn > $this->reach->today()) {
            $errors['received_on'] = ['A payment cannot be received in the future.'];
        } elseif ($this->reach->dayClosed($receivedOn)) {
            $errors['received_on'] = ['That day is closed. Record the payment on today.'];
        }
        if ($shares === [] || array_sum(array_column($shares, 'amount')) !== $amount) {
            $errors['shares'] = ['The children\'s shares must add up to the amount received.'];
        }
        if (count(array_unique(array_column($shares, 'learner_id'))) !== count($shares)) {
            $errors['shares'] = ['Give each child once.'];
        }
        foreach ($shares as $share) {
            if ($share['amount'] <= 0) {
                $errors['shares'] = ['Each child\'s share must be more than nothing.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $places = [];

        foreach ($shares as $share) {
            $at = $this->reach->learner($by, $share['learner_id'], FeesPermission::Collect);
            $places[$share['learner_id']] = (string) $at->id;
            $charge = $share['charge_id'] ?? null;

            if ($charge !== null && ! DB::table('fee_charges')->where('id', $charge)->where('learner_id', $share['learner_id'])->exists()) {
                throw new NotFoundHttpException;
            }
        }

        if ($reference !== null) {
            $used = DB::table('fee_payments')->where('method', $method->value)->whereRaw('lower(reference) = lower(?)', [$reference])->value('number');

            if (is_string($used)) {
                throw ValidationException::withMessages(['reference' => ["This reference is already on receipt {$used}."]]);
            }
        }

        return DB::transaction(function () use ($by, $method, $reference, $receivedOn, $payerName, $payerPhone, $amount, $shares, $places, $tell): string {
            $learners = array_column($shares, 'learner_id');
            sort($learners);

            foreach ($learners as $learner) {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['fees:learner:'.$learner]);
            }

            $id = Ids::next();
            $number = Numbers::next(Numbers::RECEIPT, $receivedOn);
            DB::table('fee_payments')->insert([
                'id' => $id, 'number' => $number, 'received_on' => $receivedOn, 'method' => $method->value, 'reference' => $reference,
                'payer_name' => mb_substr($payerName, 0, 120), 'payer_phone' => $payerPhone !== null && trim($payerPhone) !== '' ? mb_substr(trim($payerPhone), 0, 30) : null,
                'amount_minor' => $amount, 'received_by' => $by->id, 'created_at' => $this->clock->now(),
            ]);

            foreach ($shares as $share) {
                $charge = $share['charge_id'] ?? null;
                $before = $this->ledger->standing($share['learner_id']);
                $after = $this->ledger->standingWith($share['learner_id'], $share['amount'], $charge);
                $shareId = Ids::next();
                DB::table('fee_payment_shares')->insert([
                    'id' => $shareId, 'payment_id' => $id, 'learner_id' => $share['learner_id'], 'charge_id' => $charge, 'amount_minor' => $share['amount'],
                    'applied' => json_encode(Ledger::applied($before, $after), JSON_THROW_ON_ERROR), 'balance_after_minor' => $after['balance'],
                ]);

                if ($tell) {
                    $this->notifier->send(new PaymentReceived($shareId, $share['learner_id'], $places[$share['learner_id']], $number, $share['amount'], $after['balance']), $by);
                }
            }

            $this->audit->record(FeesAudit::PaymentRecorded, actor: $this->audit->actorFor($by),
                meta: ['payment_id' => $id, 'number' => $number, 'amount_minor' => $amount, 'method' => $method->value, 'learners' => implode(',', $learners)]);

            return $id;
        });
    }

    /** Cancels a receipt. The payment stays on the statement, and the cancellation is a line of its own. */
    public function cancel(User $by, string $paymentId, string $reason): string
    {
        $reason = trim($reason);
        $payment = DB::table('fee_payments')->where('id', $paymentId)->first(['id', 'number']);

        if ($payment === null) {
            throw new NotFoundHttpException;
        }

        foreach (DB::table('fee_payment_shares')->where('payment_id', $paymentId)->pluck('learner_id') as $learner) {
            $this->reach->learner($by, is_string($learner) ? $learner : '', FeesPermission::Adjust);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say why the receipt is cancelled.']]);
        }

        return DB::transaction(function () use ($by, $payment, $reason): string {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['fees:payment:'.Row::text($payment, 'id')]);

            $paymentId = Row::text($payment, 'id');

            if (DB::table('fee_payment_voids')->where('payment_id', $paymentId)->exists()) {
                throw ValidationException::withMessages(['payment' => ['The receipt is already cancelled.']]);
            }

            $today = $this->reach->today();
            $number = Numbers::next(Numbers::VOID, $today);
            DB::table('fee_payment_voids')->insert(['payment_id' => $paymentId, 'number' => $number, 'reason' => mb_substr($reason, 0, 1000),
                'voided_by' => $by->id, 'voided_on' => $today, 'created_at' => $this->clock->now()]);
            // The receipt's check code now says it was cancelled (D-096).
            app(DocumentChecks::class)->cancel(FeeDocuments::RECEIPT, Row::text($payment, 'number'), $today, $number);
            $this->audit->record(FeesAudit::ReceiptCancelled, actor: $this->audit->actorFor($by), reason: $reason,
                meta: ['payment_id' => $paymentId, 'number' => Row::text($payment, 'number'), 'void' => $number]);

            return $number;
        });
    }
}
