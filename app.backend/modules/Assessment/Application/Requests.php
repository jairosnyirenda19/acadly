<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Kernel\Approvals\ApprovalRequests;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Assessment changes waiting for someone's yes (D-081), as the module's own
 * screens show them: what was asked, by whom, and who the request waits for.
 *
 * @phpstan-type Pending array{id: string, asked_by: string, asked_by_id: string, asked_at: string, step: string, waiting_for: list<string>}
 */
final class Requests
{
    public function __construct(private readonly ApprovalRequests $approvals) {}

    /**
     * The publishing request of a class and term still waiting.
     *
     * @return array{subject_id: string, fingerprint: string, pending: Pending}|null
     */
    public function publish(string $classId, string $periodId): ?array
    {
        $row = $this->waiting('result_publish_requests', PublishApproval::KEY)->where('x.class_id', $classId)->where('x.period_id', $periodId)->first(['x.id', 'x.fingerprint']);
        $pending = $row !== null ? $this->approvals->pending(PublishApproval::KEY, Row::text($row, 'id')) : null;

        return $row !== null && $pending !== null ? ['subject_id' => Row::text($row, 'id'), 'fingerprint' => Row::text($row, 'fingerprint'), 'pending' => $pending] : null;
    }

    /**
     * Corrections of locked marks still waiting, by learner.
     *
     * @return array<string, array{value: float|null, reason: string|null, note: string, pending: Pending}>
     */
    public function marks(string $assessmentId): array
    {
        $out = [];

        foreach ($this->waiting('assessment_mark_requests', MarkApproval::KEY)->where('x.assessment_id', $assessmentId)->get(['x.id', 'x.learner_id', 'x.value', 'x.reason', 'x.note']) as $row) {
            $pending = $this->approvals->pending(MarkApproval::KEY, Row::text($row, 'id'));

            if ($pending !== null) {
                $value = $row->value ?? null;
                $out[Row::text($row, 'learner_id')] = ['value' => is_numeric($value) ? (float) $value : null, 'reason' => Row::maybeText($row, 'reason'), 'note' => Row::text($row, 'note'), 'pending' => $pending];
            }
        }

        return $out;
    }

    /**
     * A grading plan change still waiting, for one plan.
     *
     * @return array{subject_id: string, summary: string, reason: string, pending: Pending}|null
     */
    public function plan(string $divisionId, string $levelCode, string $periodId, ?string $subjectId): ?array
    {
        $row = $this->waiting('grading_plan_requests', PlanApproval::KEY)->where('x.division_id', $divisionId)->where('x.level_code', $levelCode)->where('x.period_id', $periodId)
            ->when($subjectId === null, fn (Builder $q): Builder => $q->whereNull('x.subject_id'), fn (Builder $q): Builder => $q->where('x.subject_id', $subjectId))
            ->first(['x.id', 'x.plan', 'x.reason']);
        $pending = $row !== null ? $this->approvals->pending(PlanApproval::KEY, Row::text($row, 'id')) : null;

        if ($row === null || $pending === null) {
            return null;
        }

        $plan = json_decode(Row::text($row, 'plan'), true);
        $parts = is_array($plan) && is_array($plan['parts'] ?? null) ? $plan['parts'] : [];
        $summary = self::summary($parts, '');

        return ['subject_id' => Row::text($row, 'id'), 'summary' => $summary, 'reason' => Row::text($row, 'reason'), 'pending' => $pending];
    }

    /**
     * A plan's parts in words, as asked: "Continuous assessment 30 · Exam 70".
     *
     * @param  array<array-key, mixed>  $parts
     */
    public static function summary(array $parts, string $unit): string
    {
        $words = [];

        foreach ($parts as $p) {
            if (is_array($p) && is_string($p['label'] ?? null) && is_int($p['weight'] ?? null)) {
                $words[] = $p['label'].' '.$p['weight'].$unit;
            }
        }

        return implode(' · ', $words);
    }

    /** The requests of a table whose approval is still waiting. */
    private function waiting(string $table, string $topicKey): Builder
    {
        return DB::table($table.' as x')->join('approval_requests as r', 'r.subject_id', '=', 'x.id')
            ->where('r.topic_key', $topicKey)->where('r.state', 'waiting')->orderByDesc('x.requested_at');
    }
}
