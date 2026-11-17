<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Approvals\ApprovalCase;
use Acadly\Kernel\Approvals\ApprovalTopic;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Application\Actions\CorrectMark;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Domain\Assessment;
use Illuminate\Support\Facades\DB;

/**
 * Correcting a mark after it is locked, in the Approvals catalogue (D-081).
 * The mark in force stays until the last yes; then the correction is a new
 * row of the mark's history, with the note that was given.
 */
final class MarkApproval implements ApprovalTopic
{
    public const string KEY = 'assessment.mark_correction';

    public function __construct(
        private readonly AcademicCalendar $calendar,
        private readonly Teaching $teaching,
        private readonly EnrolmentDirectory $learners,
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
        return 'Correcting a locked mark';
    }

    public function description(): string
    {
        return 'A change to a mark after it is locked. The mark in force stays until the last yes.';
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
        return ['raises' => 'the mark goes up'];
    }

    public function case(string $subjectId): ?ApprovalCase
    {
        $row = Ids::isValid($subjectId) ? DB::table('assessment_mark_requests')->where('id', $subjectId)->first() : null;
        $a = $row !== null ? Assessment::query()->find(Row::text($row, 'assessment_id')) : null;

        if ($row === null || ! $a instanceof Assessment) {
            return null;
        }

        $learnerId = Row::text($row, 'learner_id');
        $name = $this->learners->names([$learnerId])[$learnerId] ?? '';
        $class = $this->calendar->classNames([$a->class_id])[$a->class_id]['name'] ?? '';
        $subject = $this->teaching->subjects([$a->subject_id])[$a->subject_id]['name'] ?? '';
        $teachers = array_values(array_map(fn (array $t): string => $t['user_id'],
            array_filter($this->teaching->teachersOf([$a->class_id]), fn (array $t): bool => $t['subject_id'] === $a->subject_id)));
        $classTeacher = $this->calendar->classTeacherId($a->class_id);
        $was = self::shown($row->was_value ?? null, Row::maybeText($row, 'was_reason'));
        $to = self::shown($row->value ?? null, Row::maybeText($row, 'reason'));
        $wasValue = $row->was_value ?? null;
        $toValue = $row->value ?? null;

        return new ApprovalCase(
            requesterId: Row::text($row, 'requested_by'),
            at: ScopeRef::of(ScopeType::ClassGroup, $a->class_id),
            title: "{$name}: {$was} → {$to} of {$a->out_of}",
            where: "{$subject} · {$a->title} · {$class}",
            reason: Row::text($row, 'note'),
            link: '/marks/'.$a->id,
            related: ['subject_teacher' => $teachers, 'class_teacher' => $classTeacher !== null ? [$classTeacher] : []],
            facts: ['raises' => is_numeric($toValue) && (! is_numeric($wasValue) || (float) $toValue > (float) $wasValue)],
        );
    }

    public function settle(User $by, string $subjectId, bool $approved, ?string $reason): void
    {
        $row = DB::table('assessment_mark_requests')->where('id', $subjectId)->first();

        if ($approved && $row !== null) {
            $value = $row->value ?? null;
            app(CorrectMark::class)->handle($by, Row::text($row, 'assessment_id'), Row::text($row, 'learner_id'), is_numeric($value) ? (float) $value : null,
                Row::maybeText($row, 'reason'), Row::text($row, 'note'), approved: true);
        }
    }

    private static function shown(mixed $value, ?string $reason): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') : ($reason ?? '—');
    }
}
