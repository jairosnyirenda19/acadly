<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Actions;

use Acadly\Core\Enrolment\Application\DetailsApproval;
use Acadly\Core\Enrolment\Application\LearnerDetails;
use Acadly\Core\Enrolment\Application\Numbers;
use Acadly\Core\Enrolment\Application\Reach;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Domain\Learner;
use Acadly\Kernel\Approvals\Actions\ApprovalActions;
use Acadly\Kernel\Approvals\Approvals;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\Watch;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects a learner's details, with a reason. The earlier values stay in
 * the audit trail. A corrected admission number retires the old one, which
 * is never issued again. The Acadly ID is never changed. A change to the
 * name, date of birth or sex is asked for first when the school has steps
 * for it (D-081); the last yes applies it, and the details stay until then.
 */
final class CorrectLearner
{
    public function __construct(
        private readonly Reach $reach,
        private readonly Numbers $numbers,
        private readonly Audit $audit,
        private readonly Clock $clock,
        private readonly Approvals $approvals,
        private readonly ApprovalActions $asks,
    ) {}

    /** @param  array{first_name: string, other_names: string|null, surname: string, sex: string, date_of_birth: string, admission_number: string|null}  $input */
    public function handle(User $by, string $id, array $input, string $reason, bool $approved = false): Learner
    {
        ['learner' => $learner, 'at' => $at] = $this->reach->learner($by, $id, EnrolmentPermission::Manage);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Say why the details are corrected.']]);
        }

        if (mb_strlen(trim($reason)) > 255) {
            throw ValidationException::withMessages(['reason' => ['Give the reason in up to 255 characters.']]);
        }

        $details = LearnerDetails::check($input, $this->clock->today());
        $current = $this->numbers->of($learner->id);
        $wanted = trim((string) $input['admission_number']) === '' ? $current : strtoupper(trim((string) $input['admission_number']));
        $number = $wanted !== null && $wanted !== $current ? $this->numbers->checkOrNext($wanted, $learner->id) : null;
        $was = ['first_name' => $learner->first_name, 'other_names' => $learner->other_names, 'surname' => $learner->surname, 'sex' => $learner->sex,
            'date_of_birth' => $learner->date_of_birth->toDateString()];

        if (! $approved && DetailsApproval::changes($was, $details) !== [] && $this->approvals->needed(DetailsApproval::KEY)) {
            if (DetailsApproval::waiting($learner->id) !== null) {
                throw ValidationException::withMessages(['reason' => ['A correction of these details is already waiting for approval.']]);
            }

            DB::transaction(function () use ($by, $learner, $details, $was, $wanted, $reason): void {
                $request = Ids::next();
                DB::table('learner_detail_requests')->insert(['id' => $request, 'learner_id' => $learner->id,
                    'details' => json_encode([...$details, 'admission_number' => $wanted], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'was' => json_encode($was, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'reason' => trim($reason), 'requested_by' => $by->id, 'requested_at' => $this->clock->now()]);
                $this->asks->open(DetailsApproval::KEY, $request);
            });

            return $learner;
        }

        return DB::transaction(function () use ($by, $learner, $at, $details, $number, $current, $reason): Learner {
            $watch = Watch::of($learner);
            $learner->fill($details);
            $changed = $learner->isDirty();
            $learner->save();

            if ($number !== null) {
                $this->numbers->issue($by, $learner->id, $number);
            }

            if ($changed || $number !== null) {
                $this->audit->record(EnrolmentAudit::DetailsCorrected, subject: $learner, changes: $watch->diff(), reason: $reason,
                    actor: $this->audit->actorFor($by), at: $at, meta: $number !== null ? ['admission_number' => $number, 'was' => $current] : []);
            }

            return $learner;
        });
    }
}
