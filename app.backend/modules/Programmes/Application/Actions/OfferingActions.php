<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Programmes\Application\Place;
use Acadly\Modules\Programmes\Application\ProgrammesViews;
use Acadly\Modules\Programmes\Contracts\ProgrammesAudit;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Each semester's offerings (D-097): every course a released curriculum
 * places in that semester, split into groups, each with its lecturers.
 */
final class OfferingActions
{
    public function __construct(
        private readonly Place $place,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** Adds group A of every course the semester needs and does not have yet. Returns how many were added. */
    public function prepare(User $by, string $divisionId, string $periodId): int
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Offer);
        $period = $this->place->period($divisionId, $periodId);
        $missing = ProgrammesViews::missingCourses($divisionId, $periodId, $period['position']);

        return DB::transaction(function () use ($by, $divisionId, $periodId, $missing): int {
            $now = $this->clock->now();

            foreach ($missing as $courseId) {
                DB::table('programme_offerings')->insert(['id' => Ids::next(), 'division_id' => $divisionId, 'period_id' => $periodId, 'course_id' => $courseId,
                    'group_label' => 'A', 'size_limit' => null, 'created_at' => $now, 'updated_at' => $now]);
            }

            $this->audit->record(ProgrammesAudit::OfferingsPrepared, actor: $this->audit->actorFor($by), meta: ['period' => $periodId, 'added' => count($missing)]);

            return count($missing);
        });
    }

    /** Splits a course: adds its next group, B after A. */
    public function addGroup(User $by, string $divisionId, string $periodId, string $courseId): string
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Offer);
        $this->place->period($divisionId, $periodId);
        Place::owned('programme_courses', $courseId, $divisionId);
        $labels = DB::table('programme_offerings')->where('period_id', $periodId)->where('course_id', $courseId)->pluck('group_label')->all();

        $label = null;
        foreach (range('A', 'Z') as $letter) {
            if (! in_array($letter, $labels, true)) {
                $label = $letter;
                break;
            }
        }
        if ($label === null) {
            throw ValidationException::withMessages(['course_id' => ['This course has every group it can have.']]);
        }

        return DB::transaction(function () use ($by, $divisionId, $periodId, $courseId, $label): string {
            $id = Ids::next();
            DB::table('programme_offerings')->insert(['id' => $id, 'division_id' => $divisionId, 'period_id' => $periodId, 'course_id' => $courseId,
                'group_label' => $label, 'size_limit' => null, 'created_at' => $this->clock->now(), 'updated_at' => $this->clock->now()]);
            $this->audit->record(ProgrammesAudit::OfferingSaved, actor: $this->audit->actorFor($by), meta: ['offering' => $id, 'group' => $label]);

            return $id;
        });
    }

    /**
     * The group's size limit and its people.
     *
     * @param  list<string>  $lecturers
     * @param  list<string>  $assistants
     */
    public function save(User $by, string $divisionId, string $offeringId, ?int $limit, array $lecturers, array $assistants): void
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Offer);
        Place::owned('programme_offerings', $offeringId, $divisionId);
        $people = array_values(array_unique([...$lecturers, ...$assistants]));

        if ($limit !== null && ($limit < 1 || $limit > 2000)) {
            throw ValidationException::withMessages(['limit' => ['Give a size limit from 1 to 2000, or none.']]);
        }

        if (count($people) !== count($lecturers) + count($assistants)) {
            throw ValidationException::withMessages(['lecturers' => ['Name each person once.']]);
        }

        $staff = array_column(ProgrammesViews::staff(), 'id');
        if (array_diff($people, $staff) !== []) {
            throw ValidationException::withMessages(['lecturers' => ['Choose active members of staff.']]);
        }

        DB::transaction(function () use ($by, $offeringId, $limit, $lecturers, $assistants): void {
            DB::table('programme_offerings')->where('id', $offeringId)->update(['size_limit' => $limit, 'updated_at' => $this->clock->now()]);
            DB::table('programme_offering_staff')->where('offering_id', $offeringId)->delete();

            foreach ([...array_map(fn ($u) => [$u, 'lecturer'], $lecturers), ...array_map(fn ($u) => [$u, 'assistant'], $assistants)] as [$user, $role]) {
                DB::table('programme_offering_staff')->insert(['id' => Ids::next(), 'offering_id' => $offeringId, 'user_id' => $user, 'role' => $role]);
            }

            $this->audit->record(ProgrammesAudit::OfferingSaved, actor: $this->audit->actorFor($by), meta: ['offering' => $offeringId, 'lecturers' => count($lecturers)]);
        });
    }
}
