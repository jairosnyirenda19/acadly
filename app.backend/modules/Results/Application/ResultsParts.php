<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Results part of the learner record (D-105): every semester's results,
 * released or not, with the GPAs. A course to sit again or a deferred exam
 * needs attention.
 *
 * @phpstan-import-type Semester from Standing
 *
 * @phpstan-type Record array{semesters: list<Semester>, cumulative: float|null, credits: int, open: int}
 */
final class ResultsParts implements LearnerRecordPart
{
    /** @var array<string, Record|null> */
    private array $seen = [];

    public function __construct(
        private readonly EnrolmentDirectory $learners,
        private readonly Sheets $sheets,
        private readonly Standing $standing,
    ) {}

    public function key(): string
    {
        return 'results';
    }

    public function title(): string
    {
        return 'Results';
    }

    public function band(User $viewer, string $learnerId): array
    {
        $r = $this->record($viewer, $learnerId);
        if ($r === null || $r['semesters'] === []) {
            return [];
        }

        return [['label' => 'Cumulative GPA', 'value' => $r['cumulative'] === null ? '–' : number_format($r['cumulative'], 2),
            'note' => $r['open'] === 0 ? $r['credits'].' credits graded.' : ($r['open'] === 1 ? '1 course to sit again or sit deferred.' : "{$r['open']} courses to sit again or sit deferred."),
            'tone' => $r['open'] === 0 ? 'plain' : 'attention']];
    }

    public function attention(User $viewer, string $learnerId): int
    {
        return $this->record($viewer, $learnerId)['open'] ?? 0;
    }

    /** @return Record */
    public function tab(User $viewer, string $learnerId): array
    {
        return $this->record($viewer, $learnerId) ?? throw new NotFoundHttpException;
    }

    /** @return Record|null */
    private function record(User $viewer, string $learnerId): ?array
    {
        $key = $viewer->id.'|'.$learnerId;
        if (array_key_exists($key, $this->seen)) {
            return $this->seen[$key];
        }
        $division = $this->learners->placeOf($learnerId)['division_id'] ?? null;
        if ($division === null || ! $this->sheets->sees($viewer, $division)) {
            return $this->seen[$key] = null;
        }
        // Before release only moderation and the board see results; the record's other readers see released ones.
        $h = $this->standing->history($learnerId, ! $this->sheets->holds($viewer, ResultsPermission::Moderate, $division) && ! $this->sheets->holds($viewer, ResultsPermission::Board, $division));
        $open = 0;
        foreach ($h['semesters'] as $s) {
            foreach ($s['courses'] as $c) {
                $open += in_array($c['result'], ['fail', 'barred', 'deferred'], true) && $c['released'] ? 1 : 0;
            }
        }

        return $this->seen[$key] = [...$h, 'open' => $open];
    }
}
