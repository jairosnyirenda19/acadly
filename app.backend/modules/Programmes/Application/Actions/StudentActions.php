<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application\Actions;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Application\Place;
use Acadly\Modules\Programmes\Contracts\ProgrammesAudit;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Acadly\Modules\Programmes\Contracts\StudentStatus;
use Acadly\Modules\Programmes\Contracts\StudyMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Students in programmes (D-098). A student is placed once, on the newest
 * released curriculum, and keeps it to the end. Every change (a status, a
 * new year of study) ends the row in force and adds a new one.
 */
final class StudentActions
{
    public function __construct(
        private readonly Place $place,
        private readonly EnrolmentDirectory $directory,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function place(User $by, string $divisionId, string $learnerId, string $programmeId, int $entryYear, StudyMode $mode): string
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Register);
        $where = $this->directory->placeOf($learnerId);

        if ($where === null || ! $where['open'] || $where['division_id'] !== $divisionId) {
            throw ValidationException::withMessages(['learner_id' => ['Choose a student admitted to this division.']]);
        }

        if (DB::table('programme_students')->where('learner_id', $learnerId)->whereNull('ended_at')->exists()) {
            throw ValidationException::withMessages(['learner_id' => ['The student is in a programme already.']]);
        }

        $programme = Place::owned('programmes', $programmeId, $divisionId);

        if (($programme->retired_at ?? null) !== null || ! (bool) ($programme->open_for_intake ?? false)) {
            throw ValidationException::withMessages(['programme_id' => ['Choose a programme open for intake.']]);
        }

        if ($entryYear < 1 || $entryYear > Row::int($programme, 'years')) {
            throw ValidationException::withMessages(['entry_year' => ['Choose a year of the programme.']]);
        }

        $curriculum = DB::table('programme_curricula')->where('programme_id', $programmeId)->whereNotNull('released_at')->orderByDesc('released_at')->first(['id'])
            ?? throw ValidationException::withMessages(['programme_id' => ['The programme has no released curriculum.']]);

        return DB::transaction(function () use ($by, $divisionId, $learnerId, $programmeId, $curriculum, $entryYear, $mode): string {
            $id = Ids::next();
            DB::table('programme_students')->insert(['id' => $id, 'division_id' => $divisionId, 'learner_id' => $learnerId, 'programme_id' => $programmeId,
                'curriculum_id' => Row::text($curriculum, 'id'), 'year_of_study' => $entryYear, 'entry_year' => $entryYear, 'entry_semester' => 1,
                'mode' => $mode->value, 'status' => StudentStatus::Active->value, 'reason' => null, 'created_by' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(ProgrammesAudit::StudentPlaced, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId),
                meta: ['learner' => $learnerId, 'programme' => $programmeId, 'year' => $entryYear]);

            return $id;
        });
    }

    /** A new status, or a new year of study: the row in force ends and a new one starts. */
    public function change(User $by, string $divisionId, string $studentId, StudentStatus $status, ?int $year, string $reason): string
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Register);

        return $this->changeTrusted($by, $divisionId, $studentId, $status, $year, $reason);
    }

    /** The same, for an action that has already checked access. */
    public function changeTrusted(User $by, string $divisionId, string $studentId, StudentStatus $status, ?int $year, string $reason): string
    {
        $row = DB::table('programme_students')->where('id', $studentId)->where('division_id', $divisionId)->whereNull('ended_at')->first()
            ?? throw ValidationException::withMessages(['student' => ['This placement has been changed already. Reload the student.']]);

        return DB::transaction(function () use ($by, $divisionId, $row, $status, $year, $reason): string {
            $now = $this->clock->now();
            DB::table('programme_students')->where('id', Row::text($row, 'id'))->update(['ended_at' => $now]);
            $id = Ids::next();
            DB::table('programme_students')->insert(['id' => $id, 'division_id' => $divisionId, 'learner_id' => Row::text($row, 'learner_id'),
                'programme_id' => Row::text($row, 'programme_id'), 'curriculum_id' => Row::text($row, 'curriculum_id'), 'year_of_study' => $year ?? Row::int($row, 'year_of_study'),
                'entry_year' => Row::int($row, 'entry_year'), 'entry_semester' => Row::int($row, 'entry_semester'), 'mode' => Row::text($row, 'mode'),
                'status' => $status->value, 'reason' => $reason, 'created_by' => $by->id, 'created_at' => $now]);
            $this->audit->record(ProgrammesAudit::StudentPlaced, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId),
                meta: ['learner' => Row::text($row, 'learner_id'), 'status' => $status->value, 'year' => $year]);

            return $id;
        });
    }
}
