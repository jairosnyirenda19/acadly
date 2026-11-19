<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Institution\Contracts\DivisionView;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Acadly\Modules\Programmes\Contracts\ProgrammesSlot;
use Acadly\Modules\Programmes\Contracts\StudyShape;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Where Programmes works: one division. The permission is checked at that
 * division first, so a refusal says nothing about what the division holds.
 */
final class Place
{
    public function __construct(
        private readonly Access $access,
        private readonly SchoolDirectory $school,
        private readonly AcademicCalendar $calendar,
    ) {}

    public function division(User $who, string $divisionId, ProgrammesPermission $permission): DivisionView
    {
        $this->access->check($who, $permission, ScopeRef::of(ScopeType::Division, $divisionId));
        $division = $this->school->division($divisionId);

        // Only a division whose profile sets study by programmes has any.
        if ($division === null || $division->profile->binding(ProgrammesSlot::Shape) !== StudyShape::Programmes) {
            throw new NotFoundHttpException;
        }

        return $division;
    }

    public function allows(User $who, string $divisionId, ProgrammesPermission $permission): bool
    {
        return $this->access->allows($who, $permission, ScopeRef::of(ScopeType::Division, $divisionId));
    }

    /**
     * The semesters of the current year for a division, in order.
     *
     * @return list<array{id: string, name: string, starts_on: string, ends_on: string, position: int}>
     */
    public function periods(string $divisionId): array
    {
        $year = $this->calendar->currentYear();
        $out = [];

        foreach ($year['terms'][$divisionId] ?? [] as $i => $term) {
            $out[] = [...$term, 'position' => $i + 1];
        }

        return $out;
    }

    /** @return array{id: string, name: string, starts_on: string, ends_on: string, position: int} */
    public function period(string $divisionId, string $periodId): array
    {
        foreach ($this->periods($divisionId) as $period) {
            if ($period['id'] === $periodId) {
                return $period;
            }
        }

        throw new NotFoundHttpException;
    }

    /** A row of a Programmes table that belongs to the division, or not found. */
    public static function owned(string $table, string $id, string $divisionId): object
    {
        $row = DB::table($table)->where('id', $id)->where('division_id', $divisionId)->first();

        return $row ?? throw new NotFoundHttpException;
    }
}
