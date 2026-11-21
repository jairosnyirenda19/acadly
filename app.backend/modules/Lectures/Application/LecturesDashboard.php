<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application;

use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\Panels;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Lectures\Contracts\LecturesPermission;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lecture attendance on the dashboards (D-102, D-100): rates and counts
 * only, each division apart, and no student named. The overview shows the
 * rate this week; the Attendance view adds the rate by course this
 * semester and the lectures that need cover.
 *
 * @phpstan-import-type Panel from DashboardPanels
 */
final class LecturesDashboard implements DashboardPanels
{
    public function __construct(
        private readonly LectureDays $days,
        private readonly CourseTeaching $teaching,
    ) {}

    public function key(): string
    {
        return 'lectures';
    }

    public function views(DashboardScope $scope): array
    {
        return $this->divisions($scope) === [] ? [] : ['overview', 'attendance'];
    }

    public function panels(DashboardScope $scope, string $view): array
    {
        $divisions = $this->divisions($scope);
        if ($divisions === [] || ! in_array($view, ['overview', 'attendance'], true)) {
            return [];
        }
        $monday = CarbonImmutable::parse($scope->day)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        [$attended, $held] = $this->rate($divisions, $monday, $scope->day);
        $week = Panels::kpi('lectures_week', $view, 15, 'Lectures attended', Panels::percent($attended, $held), 'percent',
            $held === 0 ? 'No lecture attendance taken this week' : "{$attended} of {$held} this week", 'plain');
        if ($view === 'overview') {
            return [$week];
        }

        $out = [$week];
        $need = 0;
        foreach ($divisions as $d) {
            foreach (range(0, 5) as $i) {
                $day = CarbonImmutable::parse($monday)->addDays($i)->toDateString();
                foreach ($this->days->on([$d], $day) as $l) {
                    $need += $l['state'] === 'away' || $l['state'] === 'asked' ? 1 : 0;
                }
            }
        }
        $out[] = Panels::kpi('lectures_cover', $view, 16, 'Lectures needing cover', $need, 'count', 'This week', $need > 0 ? 'attention' : 'plain');

        foreach ($divisions as $d) {
            $period = $this->days->period($d, $scope->day);
            if ($period === null) {
                continue;
            }
            $rows = [];
            foreach ($this->byCourse($d, $period['id']) as $r) {
                $rate = Panels::percent($r['attended'], $r['held']);
                $rows[] = ['name' => $r['code'], 'division_id' => $d, 'value' => $rate ?? 0, 'whole' => 100, 'note' => "{$r['attended']} of {$r['held']}",
                    'tone' => $rate !== null && $rate < LectureRecord::BELOW ? 'attention' : 'plain'];
            }
            if ($rows !== []) {
                usort($rows, fn (array $a, array $b): int => $a['value'] <=> $b['value']);
                $name = $scope->name($d);
                $out[] = Panels::make('lectures_courses_'.$d, $view, 45, 'half', 'bars', 'Lecture attendance by course', $name.' · '.$period['name'],
                    null, ['unit' => 'percent', 'rows' => array_slice($rows, 0, 12)]);
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function divisions(DashboardScope $scope): array
    {
        return array_values(array_filter($scope->sees(LecturesPermission::View), fn (string $d): bool => DB::table('lecture_slots')->where('division_id', $d)->exists()));
    }

    /**
     * Attended and held marks at closed lectures between two days.
     *
     * @param  list<string>  $divisions
     * @return array{0: int, 1: int}
     */
    private function rate(array $divisions, string $from, string $to): array
    {
        $ids = DB::table('lecture_sessions')->whereIn('division_id', $divisions)->whereNotNull('closed_at')->whereBetween('held_on', [$from, $to])->pluck('id')->all();
        $attended = 0;
        $held = 0;
        foreach (Marks::latest(array_values(array_filter($ids, is_string(...)))) as $m) {
            $held++;
            $attended += Row::text($m, 'mark') === 'absent' ? 0 : 1;
        }

        return [$attended, $held];
    }

    /** @return list<array{code: string, attended: int, held: int}> */
    private function byCourse(string $divisionId, string $periodId): array
    {
        $codes = [];
        foreach ($this->teaching->offerings($divisionId, $periodId) as $o) {
            $codes[$o['id']] = $o['code'];
        }
        $sessions = DB::table('lecture_sessions as s')->join('lecture_slots as sl', 'sl.id', '=', 's.slot_id')->where('sl.period_id', $periodId)->whereNotNull('s.closed_at')
            ->get(['s.id', 's.offering_id'])->keyBy('id');
        $out = [];
        foreach (Marks::latest(array_values(array_map('strval', $sessions->keys()->all()))) as $m) {
            $s = $sessions->get(Row::text($m, 'session_id'));
            $code = is_object($s) ? ($codes[Row::text($s, 'offering_id')] ?? '') : '';
            $out[$code] ??= ['code' => $code, 'attended' => 0, 'held' => 0];
            $out[$code]['held']++;
            $out[$code]['attended'] += Row::text($m, 'mark') === 'absent' ? 0 : 1;
        }

        return array_values($out);
    }
}
