<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Enrolment\Contracts\ReportCardLine;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Actions\SubmitRegister;
use Acadly\Modules\Attendance\Contracts\Mark;
use Illuminate\Support\Facades\DB;

/**
 * Attendance on the report card: the school days a learner was present out
 * of the days the morning register was taken, from the mark in force of each
 * register. Late and excused count as present, as on the learner record.
 */
final class AttendanceReportLine implements ReportCardLine
{
    public function key(): string
    {
        return 'attendance';
    }

    public function lines(array $learnerIds, string $from, string $to): array
    {
        if ($learnerIds === []) {
            return [];
        }

        $rows = DB::table('attendance_marks as m')->join('attendance_registers as r', 'r.id', '=', 'm.register_id')
            ->whereIn('m.learner_id', $learnerIds)->where('r.kind', SubmitRegister::KIND)
            ->where('r.day', '>=', $from)->where('r.day', '<=', $to)
            ->orderBy('m.received_at')->orderBy('m.id')
            ->get(['m.learner_id', 'm.register_id', 'm.status']);

        // The mark in force is the latest of each register.
        $now = [];

        foreach ($rows as $m) {
            $now[Row::text($m, 'learner_id')][Row::text($m, 'register_id')] = Row::text($m, 'status');
        }

        $out = [];

        foreach ($now as $learner => $statuses) {
            $present = count(array_filter($statuses, fn (string $s): bool => in_array($s, [Mark::Present->value, Mark::Late->value, Mark::Excused->value], true)));
            $days = count($statuses);
            $out[$learner] = ['label' => 'Attendance', 'value' => $present.' of '.$days.($days === 1 ? ' day present' : ' days present')];
        }

        return $out;
    }
}
