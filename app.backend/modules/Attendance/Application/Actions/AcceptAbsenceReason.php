<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application\Actions;

use Acadly\Kernel\Audit\NotAudited;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\AbsenceReasons;
use Acadly\Modules\Attendance\Application\Reach;
use Acadly\Modules\Attendance\Contracts\Mark;
use Acadly\Modules\Attendance\Domain\Correction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Staff accept the reason a family gave for an absence (the approved
 * design, D-085): a register correction from absent to excused, with the
 * family's reason as its reason. The school's chain for register
 * corrections decides who approves it.
 */
#[NotAudited('It asks for a register correction, whose journey step writes the audit line.')]
final class AcceptAbsenceReason
{
    public function __construct(
        private readonly AbsenceReasons $reasons,
        private readonly CorrectionActions $corrections,
        private readonly Reach $reach,
    ) {}

    public function accept(User $by, string $registerId, string $learnerId): Correction
    {
        $register = DB::table('attendance_registers')->where('id', $registerId)->first(['class_id']);

        if ($register === null || ! $this->reach->sees($by, Row::text($register, 'class_id'))) {
            throw new NotFoundHttpException;
        }

        $reason = AbsenceReasons::forStaff($this->reasons->latest([$registerId], $learnerId), $registerId, $learnerId);

        if ($reason === null) {
            throw ValidationException::withMessages(['learner_id' => ['The family has not given a reason for this absence.']]);
        }

        $text = 'Reason from the family: '.(AbsenceReasons::LABELS[$reason['kind']] ?? $reason['kind']).'.'
            .($reason['note'] !== null ? ' “'.$reason['note'].'”' : '').' Given by '.$reason['by'].'.';

        return $this->corrections->request($by, $registerId, $learnerId, Mark::Excused->value, mb_strimwidth($text, 0, 255, '…'));
    }
}
