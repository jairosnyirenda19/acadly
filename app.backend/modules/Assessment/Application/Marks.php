<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * The marks in force: the latest row of each learner in each assessment.
 *
 * @phpstan-type Mark array{id: string, value: float|null, reason: string|null, entered_by: string, entered_at: string, corrected: bool, note: string|null}
 */
final class Marks
{
    /**
     * @param  list<string>  $assessmentIds
     * @return array<string, array<string, Mark>> assessment id => learner id => mark
     */
    public function inForce(array $assessmentIds): array
    {
        if ($assessmentIds === []) {
            return [];
        }

        $out = [];
        $rows = DB::table('assessment_marks')
            ->selectRaw('distinct on (assessment_id, learner_id) id, assessment_id, learner_id, value, reason, entered_by, entered_at, replaces_id, note')
            ->whereIn('assessment_id', array_values(array_unique($assessmentIds)))
            ->orderBy('assessment_id')->orderBy('learner_id')->orderByDesc('entered_at')->orderByDesc('id')
            ->get();

        foreach ($rows as $row) {
            $value = $row->value ?? null;
            $out[Row::text($row, 'assessment_id')][Row::text($row, 'learner_id')] = [
                'id' => Row::text($row, 'id'),
                'value' => is_numeric($value) ? (float) $value : null,
                'reason' => Row::maybeText($row, 'reason'),
                'entered_by' => Row::text($row, 'entered_by'),
                'entered_at' => Row::text($row, 'entered_at'),
                'corrected' => Row::maybeText($row, 'replaces_id') !== null,
                'note' => Row::maybeText($row, 'note'),
            ];
        }

        return $out;
    }

    /**
     * The marks in force of one learner, by assessment.
     *
     * @param  list<string>  $assessmentIds
     * @return array<string, Mark>
     */
    public function ofLearner(string $learnerId, array $assessmentIds): array
    {
        $out = [];

        foreach ($this->inForce($assessmentIds) as $assessment => $marks) {
            if (isset($marks[$learnerId])) {
                $out[$assessment] = $marks[$learnerId];
            }
        }

        return $out;
    }

    /** A mark as a percentage of the most marks, to one decimal place. */
    public static function percent(float $value, int $outOf): float
    {
        return $outOf > 0 ? round($value / $outOf * 100, 1) : 0.0;
    }
}
