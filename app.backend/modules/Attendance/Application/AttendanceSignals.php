<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerSignals;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Actions\SubmitRegister;
use Acadly\Modules\Attendance\Contracts\Mark;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Attendance's signals and figures (D-093), from the morning registers.
 * The mark in force is the latest of each register. Present and late count
 * as present; excused counts neither way.
 */
final class AttendanceSignals implements LearnerSignals
{
    /** An absence count looks back this far. */
    public const int WINDOW_DAYS = 14;

    /** Fewer school days than this in a term say nothing yet. */
    private const int ENOUGH_DAYS = 5;

    public function __construct(
        private readonly Teaching $teaching,
        private readonly EnrolmentDirectory $directory,
        private readonly Clock $clock,
    ) {}

    public function key(): string
    {
        return 'attendance';
    }

    public function label(): string
    {
        return 'Attendance';
    }

    public function rules(): array
    {
        return [
            ['key' => 'attendance.present_below', 'text' => 'Present less than {value}% this term', 'default' => 85],
            ['key' => 'attendance.absences', 'text' => '{value} absences or more in the last '.self::WINDOW_DAYS.' days', 'default' => 5],
        ];
    }

    public function signals(array $learnerIds, array $rules): array
    {
        $today = $this->clock->today();
        $out = [];
        $byTerm = [];

        foreach ($learnerIds as $id) {
            $place = $this->directory->placeOf($id);
            $term = $place === null ? null : $this->teaching->periodOn($place['division_id'], $today);

            if ($term !== null) {
                $byTerm[$term['id']]['term'] = $term;
                $byTerm[$term['id']]['learners'][] = $id;
            }
        }

        $below = $rules['attendance.present_below'] ?? 0;

        foreach ($byTerm as $group) {
            $term = $group['term'];
            $now = $this->shares($group['learners'], $term['starts_on'], min($term['ends_on'], $today));
            $before = $this->teaching->periodAt($term['division_id'], CarbonImmutable::parse($term['starts_on'])->subDay()->toDateString());
            $then = $before === null ? [] : $this->shares($group['learners'], $before['starts_on'], $before['ends_on']);

            foreach ($group['learners'] as $id) {
                $share = $now[$id] ?? null;

                if ($below > 0 && $share !== null && $share['marked'] >= self::ENOUGH_DAYS && $share['percent'] < $below) {
                    $was = $then[$id]['percent'] ?? null;
                    $out[$id][] = $share['percent'].'% present this term'.($was !== null && $was > $share['percent'] ? ", down from {$was}%." : '.');
                }
            }
        }

        $absences = $rules['attendance.absences'] ?? 0;

        if ($absences > 0) {
            $from = CarbonImmutable::parse($today)->subDays(self::WINDOW_DAYS - 1)->toDateString();

            foreach ($this->latest($learnerIds, $from, $today) as $id => $marks) {
                $n = count(array_filter($marks, fn (string $s): bool => $s === Mark::Absent->value));

                if ($n >= $absences) {
                    $out[$id][] = "{$n} absences in the last ".self::WINDOW_DAYS.' days.';
                }
            }
        }

        return $out;
    }

    public function trends(string $learnerId, array $terms): array
    {
        $values = [];
        $today = $this->clock->today();

        foreach ($terms as $term) {
            $share = $this->shares([$learnerId], $term['starts_on'], min($term['ends_on'], $today))[$learnerId] ?? null;
            $values[$term['key']] = $share !== null && $share['marked'] > 0 ? (float) $share['percent'] : null;
        }

        return [['key' => 'attendance.present', 'label' => 'Present', 'unit' => '%', 'values' => $values, 'worse' => 'down']];
    }

    /**
     * @param  list<string>  $learnerIds
     * @return array<string, array{marked: int, percent: int}>
     */
    private function shares(array $learnerIds, string $from, string $to): array
    {
        $out = [];

        foreach ($this->latest($learnerIds, $from, $to) as $id => $marks) {
            $counted = array_filter($marks, fn (string $s): bool => $s !== Mark::Excused->value);
            $present = count(array_filter($counted, fn (string $s): bool => $s === Mark::Present->value || $s === Mark::Late->value));
            $out[$id] = ['marked' => count($counted), 'percent' => $counted === [] ? 100 : (int) round($present * 100 / count($counted))];
        }

        return $out;
    }

    /**
     * The mark in force of each morning register, by learner.
     *
     * @param  list<string>  $learnerIds
     * @return array<string, array<string, string>> learner => register => status
     */
    private function latest(array $learnerIds, string $from, string $to): array
    {
        $out = [];

        foreach ($learnerIds === [] || $from > $to ? [] : DB::table('attendance_marks as m')->join('attendance_registers as r', 'r.id', '=', 'm.register_id')
            ->whereIn('m.learner_id', $learnerIds)->where('r.kind', SubmitRegister::KIND)->whereBetween('r.day', [$from, $to])
            ->orderBy('m.received_at')->orderBy('m.id')->get(['m.learner_id', 'm.register_id', 'm.status']) as $m) {
            $out[Row::text($m, 'learner_id')][Row::text($m, 'register_id')] = Row::text($m, 'status');
        }

        return $out;
    }
}
