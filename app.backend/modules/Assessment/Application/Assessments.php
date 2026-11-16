<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Contracts\AssessmentKind;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Contracts\PlanMode;
use Acadly\Modules\Assessment\Journeys\Marks\State;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A teacher's marks this term (the approved Marks screen on a phone), and
 * what the viewer may set (the approved New assessment screen).
 */
final class Assessments
{
    public function __construct(
        private readonly Reach $reach,
        private readonly Teaching $teaching,
        private readonly EnrolmentDirectory $learners,
        private readonly Marks $marks,
        private readonly Plans $plans,
        private readonly Clock $clock,
    ) {}

    /**
     * The viewer's assessments of this term: those they set, and those of the
     * classes and subjects they teach.
     *
     * @return array<string, mixed>
     */
    public function mine(User $viewer): array
    {
        $today = $this->clock->today();
        $taught = $this->teaching->taughtBy($viewer->id);
        $classes = [];

        foreach ($this->reach->classes($viewer) as $c) {
            $classes[$c['id']] = $c;
        }

        $current = [];

        foreach (array_unique(array_column($classes, 'division_id')) as $division) {
            $period = $this->teaching->periodAt($division, $today);

            if ($period !== null) {
                $current[] = $period['id'];
            }
        }

        $rows = DB::table('assessments')
            ->whereIn('class_id', array_keys($classes) ?: [Plans::NONE])
            ->whereIn('period_id', $current ?: [Plans::NONE])
            ->where(function (Builder $q) use ($viewer, $taught): void {
                $q->where('created_by', $viewer->id);

                foreach ($taught as $t) {
                    $q->orWhere(fn (Builder $one): Builder => $one->where('class_id', $t['class_id'])->where('subject_id', $t['subject_id']));
                }
            })
            ->orderByDesc('held_on')->orderByDesc('created_at')->get();

        $ids = array_values(array_map(fn (object $r): string => Row::text($r, 'id'), $rows->all()));
        $marks = $this->marks->inForce($ids);
        $subjects = $this->teaching->subjects(array_values(array_map(fn (object $r): string => Row::text($r, 'subject_id'), $rows->all())));
        $out = [];

        foreach ($rows as $r) {
            $id = Row::text($r, 'id');
            $class = $classes[Row::text($r, 'class_id')];
            $state = State::tryFrom(Row::text($r, 'state')) ?? State::Marking;
            $held = substr(Row::text($r, 'held_on'), 0, 10);
            $given = $marks[$id] ?? [];

            $out[] = [
                'id' => $id, 'class_id' => $class['id'], 'class' => $class['name'], 'subject' => $subjects[Row::text($r, 'subject_id')]['name'] ?? '',
                'kind' => Row::text($r, 'kind'), 'kind_label' => AssessmentKind::tryFrom(Row::text($r, 'kind'))?->label() ?? '', 'title' => Row::text($r, 'title'),
                'held_on' => $held, 'out_of' => Row::int($r, 'out_of'), 'state' => $state->value, 'state_label' => $state->label(),
                'learners' => count($this->learners->learnersIn($class['id'], $held)),
                'marked' => count($given),
                'absent' => count(array_filter($given, fn (array $m): bool => $m['reason'] !== null)),
            ];
        }

        $states = array_column($out, 'state');

        return [
            'today' => $today,
            'totals' => [
                'all' => count($out),
                'to_finish' => count(array_filter($states, fn (string $s): bool => State::from($s)->open())),
                'submitted' => count(array_filter($states, fn (string $s): bool => $s === State::Submitted->value)),
                'locked' => count(array_filter($states, fn (string $s): bool => $s === State::Locked->value)),
            ],
            'assessments' => $out,
            'can_set' => $this->options($viewer)['choices'] !== [],
        ];
    }

    /**
     * The classes and subjects the viewer may set an assessment for, with
     * where each kind lands in the term grade.
     *
     * @return array{today: string, kinds: list<array{value: string, label: string}>, choices: list<array<string, mixed>>}
     */
    public function options(User $viewer): array
    {
        $today = $this->clock->today();
        $taught = $this->teaching->taughtBy($viewer->id);
        $choices = [];

        foreach ($this->reach->classes($viewer) as $class) {
            if (! $this->reach->may($viewer, AssessmentPermission::Create, $class['id'])) {
                continue;
            }

            $all = $this->reach->may($viewer, AssessmentPermission::Plan, $class['id']);
            $mine = array_column(array_filter($taught, fn (array $t): bool => $t['class_id'] === $class['id']), 'subject_id');
            $period = $this->teaching->periodAt($class['division_id'], $today);

            foreach ($this->teaching->subjectsFor($class['division_id'], $class['level_code']) as $subject) {
                if (! $all && ! in_array($subject['id'], $mine, true)) {
                    continue;
                }

                $plan = $period !== null ? $this->plans->inForce($class['division_id'], $class['level_code'], $period['id'], $subject['id']) : null;
                $choices[] = [
                    'class_id' => $class['id'], 'class' => $class['name'], 'level' => $this->reach->levelLabel($class['division_id'], $class['level_code']),
                    'subject_id' => $subject['id'], 'subject' => $subject['name'],
                    'period' => $period !== null ? ['id' => $period['id'], 'name' => $period['name'], 'starts_on' => $period['starts_on'], 'ends_on' => $period['ends_on']] : null,
                    'mode' => $plan['mode'] ?? PlanMode::Continuous->value,
                    'lands' => $plan !== null ? $this->lands($plan, $class['id'], $subject['id'], $period['id']) : [],
                ];
            }
        }

        return ['today' => $today, 'kinds' => AssessmentKind::choices(), 'choices' => $choices];
    }

    /**
     * Where each kind lands: its part, the part's weight, and how many assessments already count there.
     *
     * @param  array{mode: string, parts: list<array{key: string, label: string, weight: int, kinds: list<string>, rule: string, best_n: int|null, missing: string}>}  $plan
     * @return list<array{kind: string, counts: bool, part_key: string|null, part_label: string|null, weight: int|null, rule: string|null, others: int}>
     */
    private function lands(array $plan, string $classId, string $subjectId, string $periodId): array
    {
        $existing = DB::table('assessments')->where('class_id', $classId)->where('subject_id', $subjectId)->where('period_id', $periodId)
            ->where('counts', true)->pluck('kind')->all();
        $out = [];

        foreach (AssessmentKind::cases() as $kind) {
            $part = null;

            foreach ($plan['parts'] as $p) {
                $part ??= in_array($kind->value, $p['kinds'], true) ? $p : null;
            }

            $countsHere = $part !== null && ($plan['mode'] !== PlanMode::ExamOnly->value || $kind === AssessmentKind::EndOfTerm);
            $out[] = [
                'kind' => $kind->value, 'counts' => $countsHere,
                'part_key' => $countsHere ? $part['key'] : null, 'part_label' => $countsHere ? $part['label'] : null, 'weight' => $countsHere ? $part['weight'] : null,
                'rule' => $countsHere ? $part['rule'] : null,
                'others' => $countsHere ? count(array_filter($existing, fn (mixed $k): bool => is_string($k) && in_array($k, $part['kinds'], true))) : 0,
            ];
        }

        return $out;
    }
}
