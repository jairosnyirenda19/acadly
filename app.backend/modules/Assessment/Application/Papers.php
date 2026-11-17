<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * The pages of learners' marked papers, in order (the approved design).
 *
 * @phpstan-type Page array{id: string, document_id: string, position: int, type: string}
 */
final class Papers
{
    /**
     * @param  list<string>  $assessmentIds
     * @return array<string, array<string, list<Page>>> assessment id => learner id => pages
     */
    public function of(array $assessmentIds, ?string $learnerId = null): array
    {
        if ($assessmentIds === []) {
            return [];
        }

        $rows = DB::table('assessment_papers as p')->join('documents as d', 'd.id', '=', 'p.document_id')
            ->leftJoin('document_files as f', 'f.id', '=', 'd.current_file_id')
            ->whereIn('p.assessment_id', array_values(array_unique($assessmentIds)))->whereNull('p.removed_at')->whereNull('d.purged_at')
            ->when($learnerId !== null, fn ($q) => $q->where('p.learner_id', $learnerId))
            ->orderBy('p.position')->get(['p.id', 'p.assessment_id', 'p.learner_id', 'p.document_id', 'p.position', 'f.mime_type']);
        $out = [];

        foreach ($rows as $row) {
            $out[Row::text($row, 'assessment_id')][Row::text($row, 'learner_id')][] = [
                'id' => Row::text($row, 'id'), 'document_id' => Row::text($row, 'document_id'), 'position' => Row::int($row, 'position'),
                'type' => Row::maybeText($row, 'mime_type') ?? 'application/octet-stream',
            ];
        }

        return $out;
    }
}
