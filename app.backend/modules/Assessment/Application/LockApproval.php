<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Approvals\ApprovalCase;
use Acadly\Kernel\Approvals\ApprovalTopic;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Domain\Assessment;
use Acadly\Modules\Assessment\Journeys\Marks\OutcomeId;
use Acadly\Modules\Assessment\Journeys\Marks\StepId;
use Illuminate\Support\Facades\DB;

/**
 * Locking submitted marks, in the Approvals catalogue. A yes from the last
 * step locks them; a no returns them to the teacher with the reason.
 */
final class LockApproval implements ApprovalTopic
{
    public const string KEY = 'assessment.lock';

    public function __construct(
        private readonly JourneyEngine $engine,
        private readonly AcademicCalendar $calendar,
        private readonly Teaching $teaching,
        private readonly Marks $marks,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'assessment';
    }

    public function area(): string
    {
        return 'Assessment';
    }

    public function title(): string
    {
        return 'Locking marks';
    }

    public function description(): string
    {
        return 'Submitted marks become part of term grades. A no returns them to the teacher.';
    }

    public function permission(): Permission
    {
        return AssessmentPermission::Lock;
    }

    public function relations(): array
    {
        return ['subject_teacher' => 'The subject teacher', 'class_teacher' => 'The class teacher'];
    }

    public function facts(): array
    {
        return ['below_half' => 'the class average is below 50%'];
    }

    public function case(string $subjectId): ?ApprovalCase
    {
        $a = Assessment::query()->find($subjectId);

        if (! $a instanceof Assessment) {
            return null;
        }

        $submitter = DB::table('journey_step_completions')->where('subject_type', $a->auditType())->where('subject_id', $a->id)
            ->where('step', StepId::Submit->value)->orderByDesc('seq')->value('actor_user_id');
        $class = $this->calendar->classNames([$a->class_id])[$a->class_id]['name'] ?? '';
        $subject = $this->teaching->subjects([$a->subject_id])[$a->subject_id]['name'] ?? '';
        $teachers = array_values(array_map(fn (array $t): string => $t['user_id'],
            array_filter($this->teaching->teachersOf([$a->class_id]), fn (array $t): bool => $t['subject_id'] === $a->subject_id)));
        $classTeacher = $this->calendar->classTeacherId($a->class_id);
        $values = array_values(array_filter(array_map(fn (array $m): ?float => $m['value'], $this->marks->inForce([$a->id])[$a->id] ?? []), fn (?float $v): bool => $v !== null));
        $average = $values === [] ? null : array_sum($values) / count($values) / max(1, $a->out_of) * 100;

        return new ApprovalCase(
            requesterId: is_string($submitter) ? $submitter : $a->created_by,
            at: ScopeRef::of(ScopeType::ClassGroup, $a->class_id),
            title: "{$subject} · {$a->title}",
            where: $class.' · '.date('j M', strtotime($a->heldOn()) ?: 0),
            reason: null,
            link: '/marks/'.$a->id,
            related: ['subject_teacher' => $teachers, 'class_teacher' => $classTeacher !== null ? [$classTeacher] : []],
            facts: ['below_half' => $average !== null && $average < 50],
        );
    }

    public function settle(User $by, string $subjectId, bool $approved, ?string $reason): void
    {
        $a = Assessment::query()->findOrFail($subjectId);

        $approved
            ? $this->engine->perform($a, $by, StepId::Lock, OutcomeId::Locked)
            : $this->engine->perform($a, $by, StepId::Return, OutcomeId::Returned, ['reason' => $reason ?? 'Returned.']);
    }
}
