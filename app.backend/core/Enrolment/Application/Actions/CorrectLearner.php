<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Actions;

use Acadly\Core\Enrolment\Application\LearnerDetails;
use Acadly\Core\Enrolment\Application\Numbers;
use Acadly\Core\Enrolment\Application\Reach;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Domain\Learner;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\Watch;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects a learner's details, with a reason. The earlier values stay in
 * the audit trail. A corrected admission number retires the old one, which
 * is never issued again. The Acadly ID is never changed.
 */
final class CorrectLearner
{
    public function __construct(
        private readonly Reach $reach,
        private readonly Numbers $numbers,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @param  array{first_name: string, other_names: string|null, surname: string, sex: string, date_of_birth: string, admission_number: string|null}  $input */
    public function handle(User $by, string $id, array $input, string $reason): Learner
    {
        ['learner' => $learner, 'at' => $at] = $this->reach->learner($by, $id, EnrolmentPermission::Manage);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Say why the details are corrected.']]);
        }

        $details = LearnerDetails::check($input, $this->clock->today());
        $current = $this->numbers->of($learner->id);
        $wanted = trim((string) $input['admission_number']) === '' ? $current : strtoupper(trim((string) $input['admission_number']));
        $number = $wanted !== null && $wanted !== $current ? $this->numbers->checkOrNext($wanted, $learner->id) : null;

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
