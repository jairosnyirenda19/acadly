<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Kernel\Identity\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Assessment's part of the learner record (the approved Learner results
 * design): the term grades so far, and the best six or the average. A viewer
 * who may not see the learner's assessments gets no numbers.
 */
final class LearnerResults implements LearnerRecordPart
{
    public function __construct(private readonly Results $results) {}

    public function key(): string
    {
        return 'assessment';
    }

    public function title(): string
    {
        return 'Results';
    }

    public function band(User $viewer, string $learnerId): array
    {
        try {
            $r = $this->results->learner($viewer, $learnerId);
        } catch (NotFoundHttpException) {
            return [];
        }

        /** @var list<array{ready: bool}> $grades */
        $grades = $r['grades'];
        /** @var array{kind: 'average_percent'|'average_grade'|'best_six', value: float|null, of: int} $summary */
        $summary = $r['summary'];
        /** @var array{position: int, of: int, shared: bool}|null $position */
        $position = $r['position'];
        $ready = count(array_filter($grades, fn (array $g): bool => $g['ready']));
        $value = $summary['value'];
        $none = 'No grade is ready yet.';

        return [
            $position !== null
                ? ['label' => 'Position', 'value' => ($position['shared'] ? '=' : '').$position['position'].' of '.$position['of'], 'note' => 'In the class, by total marks. Not yet published.', 'tone' => 'plain']
                : ['label' => 'Position', 'value' => '–', 'note' => "{$ready} of ".count($grades).' term grades ready. Ranked when all are.', 'tone' => 'plain'],
            match ($summary['kind']) {
                'best_six' => ['label' => 'Best six', 'value' => $value !== null ? (string) (int) $value : '–', 'note' => $summary['of'] > 0 ? "Points in {$summary['of']} subjects. Lower is better." : $none, 'tone' => 'plain'],
                'average_grade' => ['label' => 'Average grade', 'value' => $value !== null ? number_format($value, 1) : '–', 'note' => $summary['of'] > 0 ? "Across {$summary['of']} subjects. Higher is better." : $none, 'tone' => 'plain'],
                default => ['label' => 'Average', 'value' => $value !== null ? $value.'%' : '–', 'note' => $summary['of'] > 0 ? "Across {$summary['of']} subjects." : $none, 'tone' => 'plain'],
            },
        ];
    }

    public function attention(User $viewer, string $learnerId): int
    {
        return 0;
    }
}
