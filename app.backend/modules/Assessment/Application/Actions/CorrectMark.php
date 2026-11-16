<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application\Actions;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Application\Marks;
use Acadly\Modules\Assessment\Application\Sheets;
use Acadly\Modules\Assessment\Contracts\AssessmentAudit;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Journeys\Marks\State;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects one mark after the marks are locked: a new row that points at the
 * one it replaces, with a note, kept in the history and the audit trail.
 * Term grades are worked out again from it.
 */
final class CorrectMark
{
    public function __construct(
        private readonly Access $access,
        private readonly Sheets $sheets,
        private readonly EnrolmentDirectory $learners,
        private readonly Marks $marks,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function handle(User $by, string $assessmentId, string $learnerId, mixed $value, ?string $reason, string $note): void
    {
        $a = $this->sheets->find($by, $assessmentId);
        $at = ScopeRef::of(ScopeType::ClassGroup, $a->class_id);
        $this->access->check($by, AssessmentPermission::Lock, $at);

        if ($a->state !== State::Locked->value) {
            throw ValidationException::withMessages(['learner_id' => ['Only locked marks are corrected. Until then, the teacher changes them on the marks sheet.']]);
        }

        $inClass = in_array($learnerId, array_column($this->learners->learnersIn($a->class_id, $a->heldOn()), 'id'), true);
        $parsed = SaveMarks::parse(['learner_id' => $learnerId, 'value' => $value, 'reason' => $reason], $a->out_of, $inClass);
        $note = trim($note);

        if (is_string($parsed)) {
            throw ValidationException::withMessages([$inClass ? 'value' : 'learner_id' => [$parsed]]);
        }

        if ($parsed === null) {
            throw ValidationException::withMessages(['value' => ['Give the corrected mark, or a reason for no mark.']]);
        }

        if ($note === '' || mb_strlen($note) > 255) {
            throw ValidationException::withMessages(['note' => ['Say why the mark is corrected, in up to 255 characters.']]);
        }

        DB::transaction(function () use ($by, $a, $learnerId, $parsed, $note, $at): void {
            DB::table('assessments')->where('id', $a->id)->lockForUpdate()->value('id');
            $old = $this->marks->inForce([$a->id])[$a->id][$learnerId] ?? null;

            if ($old !== null && $old['value'] === $parsed['value'] && $old['reason'] === $parsed['reason']) {
                throw ValidationException::withMessages(['value' => ['This is the mark in force already.']]);
            }

            $id = Ids::next();
            DB::table('assessment_marks')->insert(['id' => $id, 'assessment_id' => $a->id, 'learner_id' => $learnerId, 'value' => $parsed['value'],
                'reason' => $parsed['reason'], 'entered_by' => $by->id, 'entered_at' => $this->clock->now(), 'replaces_id' => $old['id'] ?? null, 'note' => $note]);
            $this->audit->record(AssessmentAudit::MarkCorrected, subject: $a, reason: $note, actor: $this->audit->actorFor($by), at: $at, aboutPersonId: null,
                meta: ['learner' => $learnerId, 'mark' => $id, 'from' => self::shown($old), 'to' => self::shown($parsed)]);
        });
    }

    /** @param array{value: float|null, reason: string|null}|array<string, mixed>|null $mark */
    private static function shown(?array $mark): ?string
    {
        if ($mark === null) {
            return null;
        }

        $value = $mark['value'] ?? null;
        $reason = $mark['reason'] ?? null;

        return is_float($value) || is_int($value) ? (string) $value : (is_string($reason) ? $reason : null);
    }
}
