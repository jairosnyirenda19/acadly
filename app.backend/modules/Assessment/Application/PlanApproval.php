<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Approvals\ApprovalCase;
use Acadly\Kernel\Approvals\ApprovalTopic;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Application\Actions\SavePlan;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Illuminate\Support\Facades\DB;

/**
 * A grading plan change once the term has begun, in the Approvals catalogue
 * (D-081). The version in force stays until the last yes; then the asked
 * version is saved, with the reason that was given.
 */
final class PlanApproval implements ApprovalTopic
{
    public const string KEY = 'assessment.plan_change';

    public function __construct(
        private readonly SchoolDirectory $school,
        private readonly Teaching $teaching,
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
        return 'Grading plan change in term';
    }

    public function description(): string
    {
        return 'A new plan version after the term has begun. The version in force stays until the last yes.';
    }

    public function permission(): Permission
    {
        return AssessmentPermission::Plan;
    }

    public function relations(): array
    {
        return [];
    }

    public function facts(): array
    {
        return [];
    }

    public function case(string $subjectId): ?ApprovalCase
    {
        $row = Ids::isValid($subjectId) ? DB::table('grading_plan_requests')->where('id', $subjectId)->first() : null;
        $division = $row !== null ? $this->school->division(Row::text($row, 'division_id')) : null;

        if ($row === null || $division === null) {
            return null;
        }

        $plan = json_decode(Row::text($row, 'plan'), true);
        $parts = is_array($plan) && is_array($plan['parts'] ?? null) ? $plan['parts'] : [];
        $subjectId = Row::maybeText($row, 'subject_id');
        $subject = $subjectId !== null ? ($this->teaching->subjects([$subjectId])[$subjectId]['name'] ?? '') : null;
        $period = $this->teaching->period(Row::text($row, 'period_id'))['name'] ?? '';

        return new ApprovalCase(
            requesterId: Row::text($row, 'requested_by'),
            at: ScopeRef::of(ScopeType::Division, $division->id),
            title: Requests::summary($parts, '%'),
            where: implode(' · ', array_filter([$division->profile->levelLabel(Row::text($row, 'level_code')), $subject, $period])),
            reason: Row::text($row, 'reason'),
            link: '/settings/assessment',
        );
    }

    public function settle(User $by, string $subjectId, bool $approved, ?string $reason): void
    {
        $row = DB::table('grading_plan_requests')->where('id', $subjectId)->first();

        if (! $approved || $row === null) {
            return;
        }

        $plan = json_decode(Row::text($row, 'plan'), true);
        app(SavePlan::class)->handle($by, [
            'division_id' => Row::text($row, 'division_id'), 'level_code' => Row::text($row, 'level_code'), 'period_id' => Row::text($row, 'period_id'),
            'subject_id' => Row::maybeText($row, 'subject_id'), 'mode' => is_array($plan) ? ($plan['mode'] ?? null) : null,
            'parts' => is_array($plan) ? ($plan['parts'] ?? null) : null, 'reason' => Row::text($row, 'reason'),
        ], approved: true);
    }
}
