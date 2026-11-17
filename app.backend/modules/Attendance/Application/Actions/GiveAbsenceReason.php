<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application\Actions;

use Acadly\Core\Enrolment\Contracts\Guardianship;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\AbsenceReasons;
use Acadly\Modules\Attendance\Contracts\AttendanceAudit;
use Acadly\Modules\Attendance\Contracts\Mark;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A parent gives the reason for a morning absence of their child (the
 * approved design, D-084). The reason is kept with the absence; the mark
 * stays as it is.
 */
final class GiveAbsenceReason
{
    public function __construct(
        private readonly Guardianship $guardianship,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function give(User $parent, string $learnerId, string $day, string $kind, ?string $note): void
    {
        if (! $this->guardianship->sees($parent, $learnerId)) {
            throw new NotFoundHttpException;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            throw new NotFoundHttpException;
        }

        if (! in_array($kind, AbsenceReasons::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => ['Choose a reason.']]);
        }

        $note = trim((string) $note);

        if (mb_strlen($note) > 255) {
            throw ValidationException::withMessages(['note' => ['A note can be up to 255 characters.']]);
        }

        $today = $this->clock->today();

        if ($day > $today) {
            throw new NotFoundHttpException;
        }

        if ($day < CarbonImmutable::parse($today)->subDays(AbsenceReasons::OPEN_DAYS)->toDateString()) {
            throw ValidationException::withMessages(['day' => ['This absence is more than '.AbsenceReasons::OPEN_DAYS.' days ago. Call the school about it.']]);
        }

        $mark = DB::table('attendance_marks as m')->join('attendance_registers as r', 'r.id', '=', 'm.register_id')
            ->where('m.learner_id', $learnerId)->where('r.day', $day)->where('r.kind', SubmitRegister::KIND)
            ->orderByDesc('m.received_at')->orderByDesc('m.id')->first(['m.status', 'r.id as register_id', 'r.class_id']);

        if ($mark === null || Row::text($mark, 'status') !== Mark::Absent->value) {
            throw ValidationException::withMessages(['day' => ['The register does not show this day as absent.']]);
        }

        DB::transaction(function () use ($parent, $learnerId, $mark, $kind, $note): void {
            DB::table('attendance_absence_reasons')->insert(['id' => Ids::next(), 'register_id' => Row::text($mark, 'register_id'), 'learner_id' => $learnerId,
                'kind' => $kind, 'note' => $note !== '' ? $note : null, 'given_by' => $parent->id, 'given_at' => $this->clock->now()]);
            $this->audit->record(AttendanceAudit::AbsenceReasonGiven, actor: $this->audit->actorFor($parent), at: ScopeRef::of(ScopeType::ClassGroup, Row::text($mark, 'class_id')),
                meta: ['register' => Row::text($mark, 'register_id'), 'learner' => $learnerId, 'kind' => $kind]);
        });
    }
}
