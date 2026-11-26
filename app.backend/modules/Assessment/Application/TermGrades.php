<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Contracts\AssessmentKind;
use Acadly\Modules\Assessment\Contracts\MissingReason;
use Acadly\Modules\Assessment\Contracts\MissingRule;
use Acadly\Modules\Assessment\Contracts\OverallResult;
use Acadly\Modules\Assessment\Contracts\PartRule;
use Acadly\Modules\Assessment\Journeys\Marks\State;
use Illuminate\Support\Facades\DB;

/**
 * Term grades (11 §4, §9). Computed, never stored: for a class, a term and a
 * subject, the plan in force and the scale of the level; only locked
 * assessments that count; each part's score by its rule; the term percentage
 * as the weighted sum of the parts that have work; the grade from the bands.
 * Every grade comes with its working.
 *
 * @phpstan-import-type Plan from Plans
 * @phpstan-import-type Part from Plans
 * @phpstan-import-type Scale from Scales
 * @phpstan-import-type Mark from Marks
 * @phpstan-import-type ClassRow from Reach
 *
 * @phpstan-type Item array{id: string, title: string, kind: string, kind_label: string, held_on: string, value: float|null, reason: string|null, out_of: int, percent: float|null, counted: bool, note: string|null}
 * @phpstan-type PartWorking array{key: string, label: string, weight: int, rule: string, rule_label: string, missing: string, missing_label: string, score: float|null, assessments: list<Item>}
 * @phpstan-type Working array{ready: bool, not_ready: string|null, plan: array{version: int, mode: string, default: bool, summary: string}, parts: list<PartWorking>, percent: float|null, grade: string|null, word: string|null, passes: bool|null, pending: list<array{id: string, title: string, state: string}>}
 * @phpstan-type AssessmentRow array{id: string, subject_id: string, kind: string, title: string, held_on: string, out_of: int, counts: bool, state: string}
 */
final class TermGrades
{
    public function __construct(
        private readonly Plans $plans,
        private readonly Scales $scales,
        private readonly Marks $marks,
        private readonly Teaching $teaching,
        private readonly SubjectChoices $choices,
    ) {}

    /**
     * The term grades of a class, subject by subject, for these learners.
     *
     * @param  ClassRow  $class
     * @param  list<string>  $learnerIds
     * @return array{scale: Scale|null, subjects: list<array{id: string, name: string, short: string}>, grades: array<string, array<string, Working>>, by_average: bool}
     */
    public function forClass(array $class, string $periodId, array $learnerIds, ?string $onlySubject = null): array
    {
        $assessments = $this->assessments($class['id'], $periodId, $onlySubject);
        $marks = $this->marks->inForce(array_column($assessments, 'id'));
        $scale = $this->scales->forLevel($class['division_id'], $class['level_code']);
        $taken = $this->teaching->subjectsFor($class['division_id'], $class['level_code']);
        $ids = array_values(array_unique([...array_column($taken, 'id'), ...array_column($assessments, 'subject_id')]));

        if ($onlySubject !== null) {
            $ids = [$onlySubject];
        }

        $known = $this->teaching->subjects($ids);
        // Only the learners who take a subject are graded in it (D-117).
        $takers = $this->choices->takers($class, $ids, $learnerIds);
        $subjects = [];
        $grades = [];

        foreach ($ids as $subjectId) {
            $subject = $known[$subjectId] ?? null;

            if ($subject === null) {
                continue;
            }

            $subjects[] = ['id' => $subject['id'], 'name' => $subject['name'], 'short' => $subject['short']];
            $plan = $this->plans->inForce($class['division_id'], $class['level_code'], $periodId, $subjectId);
            $own = array_values(array_filter($assessments, fn (array $a): bool => $a['subject_id'] === $subjectId));

            $grades[$subjectId] = [];

            foreach ($takers[$subjectId] ?? $learnerIds as $learnerId) {
                $grades[$subjectId][$learnerId] = self::working($plan, $own, $marks, $learnerId, $scale);
            }
        }

        return ['scale' => $scale, 'subjects' => $subjects, 'grades' => $grades, 'by_average' => $this->choices->on($class)];
    }

    /**
     * How one learner's term grade in one subject is made.
     *
     * @param  Plan  $plan
     * @param  list<AssessmentRow>  $assessments  the subject's assessments in the term
     * @param  array<string, array<string, Mark>>  $marks
     * @param  Scale|null  $scale
     * @return Working
     */
    public static function working(array $plan, array $assessments, array $marks, string $learnerId, ?array $scale): array
    {
        $counted = array_values(array_filter($assessments, fn (array $a): bool => $a['counts']));
        $pending = array_values(array_map(fn (array $a): array => ['id' => $a['id'], 'title' => $a['title'], 'state' => $a['state']],
            array_filter($counted, fn (array $a): bool => $a['state'] !== State::Locked->value)));
        $locked = array_values(array_filter($counted, fn (array $a): bool => $a['state'] === State::Locked->value));
        usort($locked, fn (array $x, array $y): int => [$x['held_on'], $x['id']] <=> [$y['held_on'], $y['id']]);

        $notReady = $pending !== [] ? 'A counted assessment is not locked yet: '.$pending[0]['title'].'.' : null;
        $parts = [];

        foreach ($plan['parts'] as $part) {
            $items = array_values(array_filter($locked, fn (array $a): bool => in_array($a['kind'], $part['kinds'], true)));

            if ($items === [] && $notReady === null) {
                $notReady = $part['label'].' has no locked work yet.';
            }

            $parts[] = self::part($part, $items, $marks, $learnerId);
        }

        $weight = 0;
        $sum = 0.0;

        foreach ($parts as $p) {
            if ($p['score'] !== null) {
                $weight += $p['weight'];
                $sum += $p['weight'] * $p['score'];
            }
        }

        if ($notReady === null && $weight === 0) {
            $notReady = 'No work counts towards this grade yet.';
        }

        $percent = $notReady === null ? round($sum / $weight, 1) : null;
        $graded = $percent !== null && $scale !== null ? Scales::grade($scale, $percent) : null;

        return [
            'ready' => $notReady === null,
            'not_ready' => $notReady,
            'plan' => ['version' => $plan['version'], 'mode' => $plan['mode'], 'default' => $plan['default'], 'summary' => Plans::summary($plan)],
            'parts' => array_map(fn (array $p): array => [...$p, 'score' => $p['score'] !== null ? round($p['score'], 1) : null], $parts),
            'percent' => $percent,
            'grade' => $graded['grade'] ?? null,
            'word' => $graded['word'] ?? null,
            'passes' => $graded['passes'] ?? null,
            'pending' => $pending,
        ];
    }

    /**
     * The assessments of a class in a term, oldest first.
     *
     * @return list<AssessmentRow>
     */
    public function assessments(string $classId, string $periodId, ?string $subjectId = null): array
    {
        $query = DB::table('assessments')->where('class_id', $classId)->where('period_id', $periodId);

        if ($subjectId !== null) {
            $query->where('subject_id', $subjectId);
        }

        return array_values($query->orderBy('held_on')->orderBy('id')->get()->map(fn (object $r): array => [
            'id' => Row::text($r, 'id'), 'subject_id' => Row::text($r, 'subject_id'), 'kind' => Row::text($r, 'kind'), 'title' => Row::text($r, 'title'),
            'held_on' => substr(Row::text($r, 'held_on'), 0, 10), 'out_of' => Row::int($r, 'out_of'), 'counts' => (bool) ($r->counts ?? false), 'state' => Row::text($r, 'state'),
        ])->all());
    }

    /**
     * One part: each of its locked assessments with the learner's mark, and the part's score.
     *
     * @param  Part  $part
     * @param  list<AssessmentRow>  $items
     * @param  array<string, array<string, Mark>>  $marks
     * @return PartWorking
     */
    private static function part(array $part, array $items, array $marks, string $learnerId): array
    {
        $missing = MissingRule::tryFrom($part['missing']) ?? MissingRule::Zero;
        $rule = PartRule::tryFrom($part['rule']) ?? PartRule::Average;
        $rows = [];
        $percents = [];
        $got = 0.0;
        $most = 0;

        foreach ($items as $a) {
            $mark = $marks[$a['id']][$learnerId] ?? null;
            $reason = $mark !== null && $mark['reason'] !== null ? MissingReason::tryFrom($mark['reason']) : null;
            $value = $mark['value'] ?? null;
            $note = null;

            if ($mark === null) {
                $note = 'Not in the class on the day.';
            } elseif ($value === null && $reason?->isExcused() === true) {
                $note = $reason->label().': left out.';
            } elseif ($value === null && $missing === MissingRule::LeftOut) {
                $note = 'Absent: left out.';
            } elseif ($value === null) {
                $value = 0.0;
                $note = 'Absent without a reason: counted as zero.';
            }

            $counted = $value !== null;
            $percent = $value !== null ? $value / max(1, $a['out_of']) * 100 : null;

            if ($percent !== null) {
                $percents[] = $percent;
                $got += (float) $value;
                $most += $a['out_of'];
            }

            $rows[] = [
                'id' => $a['id'], 'title' => $a['title'], 'kind' => $a['kind'], 'kind_label' => AssessmentKind::tryFrom($a['kind'])?->label() ?? $a['kind'],
                'held_on' => $a['held_on'], 'value' => $mark['value'] ?? null, 'reason' => $mark['reason'] ?? null, 'out_of' => $a['out_of'],
                'percent' => $percent !== null ? round($percent, 1) : null, 'counted' => $counted, 'note' => $note,
            ];
        }

        $score = match (true) {
            $percents === [] => null,
            $rule === PartRule::WeightedByMax => $most > 0 ? $got / $most * 100 : null,
            $rule === PartRule::Single => $percents[count($percents) - 1],
            $rule === PartRule::BestN => self::mean(array_slice(self::sortedDesc($percents), 0, max(1, $part['best_n'] ?? 1))),
            default => self::mean($percents),
        };

        return [
            'key' => $part['key'], 'label' => $part['label'], 'weight' => $part['weight'],
            'rule' => $rule->value, 'rule_label' => $rule === PartRule::BestN ? $rule->label().' '.($part['best_n'] ?? 1) : $rule->label(),
            'missing' => $missing->value, 'missing_label' => $missing->label(),
            'score' => $score, 'assessments' => $rows,
        ];
    }

    /** @param list<float> $values */
    private static function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    private static function sortedDesc(array $values): array
    {
        rsort($values);

        return $values;
    }

    /**
     * The learner summed up across subjects, as the scale says: the average
     * percentage, the average grade (highest is best) or the best six (the six
     * best grades as points, lowest is best). Only grades that are ready count.
     *
     * @param  Scale|null  $scale
     * @param  array<string, Working>  $bySubject
     * @return array{kind: 'average_percent'|'average_grade'|'best_six', value: float|null, of: int}
     */
    public static function summary(?array $scale, array $bySubject): array
    {
        $ready = array_values(array_filter($bySubject, fn (array $w): bool => $w['ready'] && $w['percent'] !== null));
        $overall = $scale['overall'] ?? OverallResult::AveragePercent;
        $points = array_values(array_filter(array_map(fn (array $w): ?float => is_numeric($w['grade']) ? (float) $w['grade'] : null, $ready), fn (?float $p): bool => $p !== null));

        if ($overall === OverallResult::BestSix) {
            sort($points);
            $best = array_slice($points, 0, 6);

            return ['kind' => 'best_six', 'value' => $best === [] ? null : array_sum($best), 'of' => count($best)];
        }

        if ($overall === OverallResult::AverageGrade) {
            return ['kind' => 'average_grade', 'value' => $points === [] ? null : round(array_sum($points) / count($points), 1), 'of' => count($points)];
        }

        $percents = array_map(fn (array $w): float => (float) $w['percent'], $ready);

        return ['kind' => 'average_percent', 'value' => $percents === [] ? null : round(array_sum($percents) / count($percents), 1), 'of' => count($percents)];
    }

    /**
     * One learner's grades in the subjects they take.
     *
     * @param  array<string, array<string, Working>>  $grades  subject => learner => working
     * @return array<string, Working>
     */
    public static function taken(array $grades, string $learnerId): array
    {
        $out = [];

        foreach ($grades as $subject => $byLearner) {
            if (isset($byLearner[$learnerId])) {
                $out[$subject] = $byLearner[$learnerId];
            }
        }

        return $out;
    }

    /**
     * Total marks: the term percentages added up, each subject out of 100.
     * Complete only when every subject has a grade ready.
     *
     * @param  array<string, Working>  $bySubject
     * @return array{total: float, out_of: int, complete: bool}
     */
    public static function total(array $bySubject): array
    {
        $total = 0.0;

        foreach ($bySubject as $w) {
            $total += $w['ready'] && $w['percent'] !== null ? $w['percent'] : 0.0;
        }

        $complete = $bySubject !== [] && array_filter($bySubject, fn (array $w): bool => ! $w['ready'] || $w['percent'] === null) === [];

        return ['total' => round($total, 1), 'out_of' => 100 * count($bySubject), 'complete' => $complete];
    }

    /**
     * Class positions by total marks: the highest total is first; equal totals
     * share a position and the next is skipped (1, 2, 2, 4). Only complete
     * totals are ranked. Where learners take different subjects (D-117), by
     * the average of the subjects each takes instead.
     *
     * @param  array<string, array{total: float, out_of: int, complete: bool}>  $totals  by learner id
     * @return array<string, array{position: int, of: int, shared: bool}>
     */
    public static function positions(array $totals, bool $byAverage = false): array
    {
        $ranked = [];

        foreach (array_filter($totals, fn (array $t): bool => $t['complete']) as $id => $t) {
            $ranked[$id] = ['total' => $byAverage ? ($t['out_of'] > 0 ? round($t['total'] * 100 / $t['out_of'], 1) : 0.0) : $t['total']];
        }

        uasort($ranked, fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        $counts = array_count_values(array_map(fn (array $t): string => (string) $t['total'], $ranked));
        $out = [];
        $place = 0;
        $previous = null;
        $i = 0;

        foreach ($ranked as $id => $t) {
            $i++;

            if ($t['total'] !== $previous) {
                $place = $i;
                $previous = $t['total'];
            }

            $out[$id] = ['position' => $place, 'of' => count($ranked), 'shared' => ($counts[(string) $t['total']] ?? 0) > 1];
        }

        return $out;
    }
}
