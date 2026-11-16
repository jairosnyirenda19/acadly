<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Contracts\OverallResult;
use Illuminate\Support\Facades\DB;

/**
 * The grading scales, and the scale of each level (11 §5). A percentage is
 * rounded to a whole number to find its band.
 *
 * @phpstan-type Band array{grade: string, word: string, from: int, to: int}
 * @phpstan-type Scale array{id: string, name: string, overall: OverallResult, pass_from: string|null, bands: list<Band>, track: string, position: int, retired: bool}
 * @phpstan-type Graded array{grade: string, word: string, passes: bool}
 */
final class Scales
{
    /** @var array<string, Scale>|null */
    private ?array $all = null;

    /** @var array<string, string>|null division:level => scale id */
    private ?array $levels = null;

    /** @return array<string, Scale> every scale, retired ones too, in order */
    public function all(): array
    {
        if ($this->all === null) {
            $this->all = [];

            foreach (DB::table('assessment_scales')->orderBy('position')->orderBy('name')->get() as $row) {
                $this->all[Row::text($row, 'id')] = self::scaleOf($row);
            }
        }

        return $this->all;
    }

    /** @return Scale|null */
    public function get(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * The scale a level uses: the one chosen, else the one it starts with.
     *
     * @return Scale|null null only before any scale is seeded
     */
    public function forLevel(string $divisionId, string $levelCode): ?array
    {
        $chosen = $this->levelChoices()[$divisionId.':'.$levelCode] ?? null;

        if ($chosen !== null && isset($this->all()[$chosen])) {
            return $this->all()[$chosen];
        }

        $seed = ScaleSeeds::LEVELS[$levelCode] ?? ScaleSeeds::FIRST;
        $id = DB::table('assessment_scales')->where('seed_key', $seed)->value('id');

        return is_string($id) ? $this->get($id) : (array_values($this->all())[0] ?? null);
    }

    /** @return array<string, string> division:level => scale id */
    public function levelChoices(): array
    {
        if ($this->levels === null) {
            $this->levels = [];

            foreach (DB::table('assessment_level_scales')->get(['division_id', 'level_code', 'scale_id']) as $row) {
                $this->levels[Row::text($row, 'division_id').':'.Row::text($row, 'level_code')] = Row::text($row, 'scale_id');
            }
        }

        return $this->levels;
    }

    public function forget(): void
    {
        $this->all = null;
        $this->levels = null;
    }

    /**
     * The grade of a percentage on a scale.
     *
     * @param  Scale  $scale
     * @return Graded|null
     */
    public static function grade(array $scale, float $percent): ?array
    {
        $whole = (int) round($percent, 0, PHP_ROUND_HALF_UP);
        $passRank = null;

        foreach ($scale['bands'] as $rank => $band) {
            if ($band['grade'] === $scale['pass_from']) {
                $passRank = $rank;
            }
        }

        foreach ($scale['bands'] as $rank => $band) {
            if ($whole >= $band['from'] && $whole <= $band['to']) {
                return ['grade' => $band['grade'], 'word' => $band['word'], 'passes' => $passRank === null || $rank <= $passRank];
            }
        }

        return null;
    }

    /**
     * Whether bands cover 0 to 100 in whole percentages, with no gap and no overlap.
     *
     * @param  list<Band>  $bands
     */
    public static function covers(array $bands): bool
    {
        usort($bands, fn (array $a, array $b): int => $a['from'] <=> $b['from']);
        $next = 0;

        foreach ($bands as $band) {
            if ($band['from'] !== $next || $band['to'] < $band['from']) {
                return false;
            }

            $next = $band['to'] + 1;
        }

        return $next === 101;
    }

    /** @return Scale */
    private static function scaleOf(object $row): array
    {
        $decoded = json_decode(Row::text($row, 'bands'), true);
        $bands = [];

        foreach (is_array($decoded) ? $decoded : [] as $band) {
            if (is_array($band)) {
                $bands[] = [
                    'grade' => is_string($band['grade'] ?? null) ? $band['grade'] : '',
                    'word' => is_string($band['word'] ?? null) ? $band['word'] : '',
                    'from' => is_int($band['from'] ?? null) ? $band['from'] : 0,
                    'to' => is_int($band['to'] ?? null) ? $band['to'] : 0,
                ];
            }
        }

        usort($bands, fn (array $a, array $b): int => $b['from'] <=> $a['from']);

        return [
            'id' => Row::text($row, 'id'),
            'name' => Row::text($row, 'name'),
            'overall' => OverallResult::tryFrom(Row::text($row, 'overall')) ?? OverallResult::AveragePercent,
            'pass_from' => Row::maybeText($row, 'pass_from'),
            'bands' => $bands,
            'track' => Row::text($row, 'track'),
            'position' => Row::int($row, 'position'),
            'retired' => Row::maybeText($row, 'retired_at') !== null,
        ];
    }
}
