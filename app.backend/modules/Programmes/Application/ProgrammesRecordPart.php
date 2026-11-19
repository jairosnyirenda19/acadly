<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application;

use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Acadly\Modules\Programmes\Contracts\StudentStatus;
use Acadly\Modules\Programmes\Journeys\Registration\State;
use Illuminate\Support\Facades\DB;

/**
 * The Programmes part of the learner record (D-098): the student's
 * programme, year of study and latest semester registration, for those who
 * may see programmes in the student's division.
 */
final class ProgrammesRecordPart implements LearnerRecordPart
{
    public function __construct(private readonly Access $access) {}

    public function key(): string
    {
        return 'programmes';
    }

    public function title(): string
    {
        return 'Programme';
    }

    public function band(User $viewer, string $learnerId): array
    {
        $s = $this->student($viewer, $learnerId);

        if ($s === null) {
            return [];
        }

        $r = DB::table('programme_registrations as r')->join('academic_periods as ap', 'ap.id', '=', 'r.period_id')->where('r.learner_id', $learnerId)
            ->orderByDesc('ap.starts_on')->first(['r.state', 'ap.name']);
        $status = StudentStatus::tryFrom(Row::text($s, 'status')) ?? StudentStatus::Active;
        $note = $r !== null ? Row::text($r, 'name').': '.(State::tryFrom(Row::text($r, 'state'))?->label() ?? '').'.' : 'No semester registration yet.';

        return [['label' => 'Programme', 'value' => Row::text($s, 'code').' · Year '.Row::int($s, 'year_of_study'),
            'note' => $status === StudentStatus::Active ? $note : $status->label().'.', 'tone' => 'plain']];
    }

    public function attention(User $viewer, string $learnerId): int
    {
        return 0;
    }

    private function student(User $viewer, string $learnerId): ?object
    {
        $s = DB::table('programme_students as s')->join('programmes as p', 'p.id', '=', 's.programme_id')->where('s.learner_id', $learnerId)->whereNull('s.ended_at')
            ->first(['s.division_id', 's.status', 's.year_of_study', 'p.code']);

        return $s !== null && $this->access->allows($viewer, ProgrammesPermission::View, ScopeRef::of(ScopeType::Division, Row::text($s, 'division_id'))) ? $s : null;
    }
}
