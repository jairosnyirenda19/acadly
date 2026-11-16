<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Contracts\AssessmentKind;
use Acadly\Modules\Assessment\Contracts\MissingRule;
use Acadly\Modules\Assessment\Contracts\PartRule;
use Acadly\Modules\Assessment\Contracts\PlanMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Grading plans (11 §4, §9). For a subject of a level in a term, the plan in
 * force is the subject's own plan, else the level's school plan, else the
 * default: Continuous assessment 40 + End-of-term exam 60. Within one plan
 * the highest version is in force.
 *
 * @phpstan-type Part array{key: string, label: string, weight: int, kinds: list<string>, rule: string, best_n: int|null, missing: string}
 * @phpstan-type Plan array{id: string|null, version: int, mode: string, parts: list<Part>, reason: string|null, created_by: string|null, created_at: string|null, default: bool, subject_id: string|null}
 */
final class Plans
{
    /** An id that matches nothing, for an empty `whereIn`. */
    public const string NONE = '00000000-0000-0000-0000-000000000000';

    /** @var array<string, Plan|null> */
    private array $known = [];

    /** @return Plan */
    public function inForce(string $divisionId, string $levelCode, string $periodId, ?string $subjectId): array
    {
        if ($subjectId !== null) {
            $own = $this->latest($divisionId, $levelCode, $periodId, $subjectId);

            if ($own !== null) {
                return $own;
            }
        }

        return $this->latest($divisionId, $levelCode, $periodId, null) ?? self::default();
    }

    /** @return Plan|null the highest version of exactly this plan */
    public function latest(string $divisionId, string $levelCode, string $periodId, ?string $subjectId): ?array
    {
        $key = implode(':', [$divisionId, $levelCode, $periodId, $subjectId ?? '-']);

        if (! array_key_exists($key, $this->known)) {
            $row = $this->of($divisionId, $levelCode, $periodId, $subjectId)->orderByDesc('version')->first();
            $this->known[$key] = $row === null ? null : self::planOf($row);
        }

        return $this->known[$key];
    }

    /** @return list<Plan> every version of exactly this plan, newest first */
    public function versions(string $divisionId, string $levelCode, string $periodId, ?string $subjectId): array
    {
        return array_values(array_map(fn (object $row): array => self::planOf($row),
            $this->of($divisionId, $levelCode, $periodId, $subjectId)->orderByDesc('version')->get()->all()));
    }

    /** @return array<string, Plan> subject id => the subject's own plan in force */
    public function subjectPlans(string $divisionId, string $levelCode, string $periodId): array
    {
        $out = [];

        foreach (DB::table('grading_plans')->where('division_id', $divisionId)->where('level_code', $levelCode)->where('period_id', $periodId)
            ->whereNotNull('subject_id')->orderBy('version')->get() as $row) {
            $out[Row::text($row, 'subject_id')] = self::planOf($row);
        }

        return $out;
    }

    public function forget(): void
    {
        $this->known = [];
    }

    /** @return Plan */
    public static function default(): array
    {
        $exam = AssessmentKind::EndOfTerm->value;

        return [
            'id' => null, 'version' => 0, 'mode' => PlanMode::Continuous->value, 'reason' => null, 'created_by' => null, 'created_at' => null,
            'default' => true, 'subject_id' => null,
            'parts' => [
                ['key' => 'continuous', 'label' => 'Continuous assessment', 'weight' => 40,
                    'kinds' => array_values(array_filter(array_map(fn (AssessmentKind $k): string => $k->value, AssessmentKind::cases()), fn (string $k): bool => $k !== $exam)),
                    'rule' => PartRule::Average->value, 'best_n' => null, 'missing' => MissingRule::Zero->value],
                self::examPart(60),
            ],
        ];
    }

    /** @return Part the end-of-term exam as one part */
    public static function examPart(int $weight): array
    {
        return ['key' => 'exam', 'label' => 'End-of-term exam', 'weight' => $weight, 'kinds' => [AssessmentKind::EndOfTerm->value],
            'rule' => PartRule::Single->value, 'best_n' => null, 'missing' => MissingRule::Ask->value];
    }

    /**
     * The part of a plan that accepts a kind, or null when none does.
     *
     * @param  Plan  $plan
     * @return Part|null
     */
    public static function partFor(array $plan, string $kind): ?array
    {
        foreach ($plan['parts'] as $part) {
            if (in_array($kind, $part['kinds'], true)) {
                return $part;
            }
        }

        return null;
    }

    /**
     * The weights in words: "Continuous 40 · Exam 60".
     *
     * @param  Plan  $plan
     */
    public static function summary(array $plan): string
    {
        return implode(' · ', array_map(fn (array $p): string => $p['label'].' '.$p['weight'], $plan['parts']));
    }

    private function of(string $divisionId, string $levelCode, string $periodId, ?string $subjectId): Builder
    {
        $query = DB::table('grading_plans')->where('division_id', $divisionId)->where('level_code', $levelCode)->where('period_id', $periodId);

        return $subjectId === null ? $query->whereNull('subject_id') : $query->where('subject_id', $subjectId);
    }

    /** @return Plan */
    private static function planOf(object $row): array
    {
        $decoded = json_decode(Row::text($row, 'parts'), true);
        $parts = [];

        foreach (is_array($decoded) ? $decoded : [] as $p) {
            if (! is_array($p)) {
                continue;
            }

            $parts[] = [
                'key' => is_string($p['key'] ?? null) ? $p['key'] : '',
                'label' => is_string($p['label'] ?? null) ? $p['label'] : '',
                'weight' => is_int($p['weight'] ?? null) ? $p['weight'] : 0,
                'kinds' => array_values(array_filter(is_array($p['kinds'] ?? null) ? $p['kinds'] : [], is_string(...))),
                'rule' => is_string($p['rule'] ?? null) ? $p['rule'] : PartRule::Average->value,
                'best_n' => is_int($p['best_n'] ?? null) ? $p['best_n'] : null,
                'missing' => is_string($p['missing'] ?? null) ? $p['missing'] : MissingRule::Zero->value,
            ];
        }

        return [
            'id' => Row::text($row, 'id'),
            'version' => Row::int($row, 'version'),
            'mode' => Row::text($row, 'mode'),
            'parts' => $parts,
            'reason' => Row::maybeText($row, 'reason'),
            'created_by' => Row::text($row, 'created_by'),
            'created_at' => Row::text($row, 'created_at'),
            'default' => false,
            'subject_id' => Row::maybeText($row, 'subject_id'),
        ];
    }
}
