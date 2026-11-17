<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Enrolment\Contracts\FamilyLine;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Actions\SubmitRegister;
use Acadly\Modules\Attendance\Contracts\Mark;
use Illuminate\Support\Facades\DB;

/** The parent portal's line for today: at school, late, absent (and whether the family gave a reason) or excused, from the morning register. */
final class AttendanceFamilyLine implements FamilyLine
{
    public function __construct(private readonly Clock $clock, private readonly AbsenceReasons $reasons) {}

    public function line(string $learnerId, ?string $classId): ?array
    {
        $mark = DB::table('attendance_marks as m')->join('attendance_registers as r', 'r.id', '=', 'm.register_id')
            ->where('m.learner_id', $learnerId)->where('r.day', $this->clock->today())->where('r.kind', SubmitRegister::KIND)
            ->orderByDesc('m.received_at')->orderByDesc('m.id')->first(['m.status', 'm.register_id']);

        if ($mark === null) {
            return null;
        }

        return match (Mark::tryFrom(Row::text($mark, 'status'))) {
            Mark::Present => ['key' => 'today', 'word' => 'present', 'label' => null, 'tone' => 'done'],
            Mark::Late => ['key' => 'today', 'word' => 'late', 'label' => null, 'tone' => 'attention'],
            // Absent with no reason from the family yet: the portal asks for one (D-084).
            Mark::Absent => ['key' => 'today', 'word' => $this->reasons->latest([Row::text($mark, 'register_id')], $learnerId) === [] ? 'absent_ask' : 'absent', 'label' => null, 'tone' => 'wrong'],
            Mark::Excused => ['key' => 'today', 'word' => 'excused', 'label' => null, 'tone' => 'plain'],
            null => null,
        };
    }
}
