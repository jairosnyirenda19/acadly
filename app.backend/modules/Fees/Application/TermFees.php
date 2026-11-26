<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Contracts\StudentProgrammes;
use Acadly\Modules\Fees\Domain\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The fees of each term, by division: what each level pays, the school's
 * instalment plan, and who is billed. Terms come from the calendar: this
 * school year and the next one, once it is set up.
 *
 * @phpstan-type Term array{period_id: string, division_id: string, division: string, year: string, year_id: string, name: string, starts_on: string, ends_on: string}
 * @phpstan-type Bill array{learner_id: string, level_code: string, lines: list<array{item_id: string, label: string, amount: int}>, total: int}
 */
final class TermFees
{
    public function __construct(
        private readonly AcademicCalendar $calendar,
        private readonly SchoolDirectory $school,
        private readonly EnrolmentDirectory $directory,
        private readonly Clock $clock,
    ) {}

    /** @return list<Term> */
    public function terms(): array
    {
        $years = array_filter([$this->calendar->currentYear(), ($next = $this->calendar->nextYear()) !== null ? $this->calendar->currentYear($next['starts_on']) : null]);
        $terms = [];
        $seen = [];

        foreach ($years as $year) {
            if (isset($seen[$year['id']])) {
                continue;
            }

            $seen[$year['id']] = true;

            foreach ($this->school->divisionViews() as $division) {
                foreach ($year['terms'][$division->id] ?? [] as $term) {
                    $terms[] = ['period_id' => $term['id'], 'division_id' => $division->id, 'division' => $division->name, 'year' => $year['name'], 'year_id' => $year['id'],
                        'name' => $term['name'], 'starts_on' => $term['starts_on'], 'ends_on' => $term['ends_on']];
                }
            }
        }

        return $terms;
    }

    /** @return Term */
    public function term(string $periodId, string $divisionId): array
    {
        foreach ($this->terms() as $term) {
            if ($term['period_id'] === $periodId && $term['division_id'] === $divisionId) {
                return $term;
            }
        }

        throw new NotFoundHttpException;
    }

    /**
     * The term in force today for a division, or the next one.
     *
     * @return Term|null
     */
    public function currentTerm(string $divisionId): ?array
    {
        $today = $this->clock->today();
        $next = null;

        foreach ($this->terms() as $term) {
            if ($term['division_id'] !== $divisionId) {
                continue;
            }
            if ($term['starts_on'] <= $today && $term['ends_on'] >= $today) {
                return $term;
            }
            if ($term['starts_on'] > $today && ($next === null || $term['starts_on'] < $next['starts_on'])) {
                $next = $term;
            }
        }

        return $next;
    }

    /** @return list<array{code: string, label: string}> */
    public function levels(string $divisionId): array
    {
        return $this->school->division($divisionId)?->profile->levels ?? [];
    }

    public function scheduleId(string $periodId, string $divisionId): ?string
    {
        $id = DB::table('fee_schedules')->where('period_id', $periodId)->where('division_id', $divisionId)->value('id');

        return is_string($id) ? $id : null;
    }

    /**
     * What billing the term would charge each learner not yet billed.
     *
     * @param  Term  $term
     * @return list<Bill>
     */
    public function bills(array $term): array
    {
        $schedule = $this->scheduleId($term['period_id'], $term['division_id']);

        if ($schedule === null) {
            return [];
        }

        $amounts = [];

        foreach (DB::table('fee_schedule_amounts as a')->join('fee_items as i', 'i.id', '=', 'a.item_id')->where('a.schedule_id', $schedule)
            ->orderBy('i.position')->get(['a.item_id', 'a.level_code', 'a.amount_minor', 'i.name', 'i.optional']) as $a) {
            $amounts[Row::text($a, 'level_code')][] = ['item_id' => Row::text($a, 'item_id'), 'label' => Row::text($a, 'name'), 'amount' => Row::int($a, 'amount_minor'), 'optional' => (bool) $a->optional];
        }

        $billed = array_flip(DB::table('fee_charges')->where('schedule_id', $schedule)->pluck('learner_id')->filter(fn ($v) => is_string($v))->all());
        $day = max($this->clock->today(), $term['starts_on']);
        $day = min($day, $term['ends_on']);
        $bills = [];
        $learners = [];

        foreach ($this->calendar->classesInYear($term['year_id']) as $class) {
            if ($class['division_id'] !== $term['division_id']) {
                continue;
            }

            foreach ($this->directory->learnersIn($class['id'], $day) as $learner) {
                if (! isset($billed[$learner['id']]) && ! isset($learners[$learner['id']])) {
                    $learners[$learner['id']] = $class['level_code'];
                }
            }
        }

        // A college's fees by programme and mode (D-116): they replace the default amount of their item.
        $byProgramme = $this->programmeAmounts($schedule);
        $students = $byProgramme === [] || $learners === [] || ! app()->bound(StudentProgrammes::class) ? []
            : app(StudentProgrammes::class)->students(array_map('strval', array_keys($learners)), $term['period_id']);
        $takes = [];

        foreach ($learners === [] ? [] : DB::table('fee_item_takers')->whereIn('learner_id', array_keys($learners))->whereNull('ended_at')->get(['learner_id', 'item_id']) as $t) {
            $takes[Row::text($t, 'learner_id')][Row::text($t, 'item_id')] = true;
        }

        foreach ($learners as $id => $level) {
            $lines = [];
            $own = isset($students[$id]) ? ($byProgramme[$students[$id]['programme_id'].'|'.$students[$id]['mode']] ?? []) : [];
            $waiting = false;

            foreach ($amounts[$level] ?? [] as $line) {
                $amount = $line['amount'];

                if (isset($own[$line['item_id']])) {
                    $p = $own[$line['item_id']];
                    $credits = $students[$id]['credits'] ?? null;

                    if (isset($p['credit'])) {
                        // Per registered credit: billed once a registration is approved.
                        $waiting = $waiting || $credits === null;
                        $amount = $p['credit'] * ($credits ?? 0);
                    } else {
                        $amount = $p[$level] ?? $amount;
                    }
                }

                if ($amount > 0 && (! $line['optional'] || isset($takes[$id][$line['item_id']]))) {
                    $lines[] = ['item_id' => $line['item_id'], 'label' => $line['label'], 'amount' => $amount];
                }
            }

            if ($waiting) {
                continue;
            }

            $total = array_sum(array_column($lines, 'amount'));

            if ($total > 0) {
                $bills[] = ['learner_id' => (string) $id, 'level_code' => $level, 'lines' => $lines, 'total' => $total];
            }
        }

        return $bills;
    }

    /**
     * A schedule's amounts by programme and mode, by item: per year of study, or 'credit' for an amount per credit.
     *
     * @return array<string, array<string, array<string, int>>> "programme|mode" => item id => level code or 'credit' => amount
     */
    public function programmeAmounts(string $scheduleId): array
    {
        $out = [];

        foreach (DB::table('fee_schedule_programme_amounts')->where('schedule_id', $scheduleId)->get(['item_id', 'programme_id', 'mode', 'level_code', 'amount_minor']) as $a) {
            $out[Row::text($a, 'programme_id').'|'.Row::text($a, 'mode')][Row::text($a, 'item_id')][Row::text($a, 'level_code')] = Row::int($a, 'amount_minor');
        }

        return $out;
    }

    /**
     * The school's instalment plan of a schedule, split for one total: parts always add up to the total.
     *
     * @return list<array{position: int, label: string, due_on: string, amount: int}>
     */
    public static function split(string $scheduleId, int $total): array
    {
        $plan = DB::table('fee_schedule_instalments')->where('schedule_id', $scheduleId)->orderBy('position')->get(['position', 'label', 'due_on', 'share_bp'])->all();
        $parts = Money::split($total, array_values(array_map(fn (object $p): int => Row::int($p, 'share_bp'), $plan)));
        $out = [];

        foreach ($plan as $i => $p) {
            if ($parts[$i] > 0) {
                $out[] = ['position' => Row::int($p, 'position'), 'label' => Row::text($p, 'label'), 'due_on' => Row::text($p, 'due_on'), 'amount' => $parts[$i]];
            }
        }

        return $out;
    }
}
