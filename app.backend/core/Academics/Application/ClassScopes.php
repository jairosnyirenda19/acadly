<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\ScopeParents;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Classes in the scope tree: a class in force sits in the offering of its
 * division at its campus. When that offering has ended the class still sits
 * in its division and its campus. A class is named with its campus when the
 * school has more than one.
 */
final class ClassScopes implements ScopeParents
{
    public function __construct(
        private readonly SchoolDirectory $school,
        private readonly AcademicCalendar $calendar,
    ) {}

    public function types(): array
    {
        return [ScopeType::ClassGroup];
    }

    public function parents(ScopeRef $scope): ?array
    {
        $class = DB::table('academic_classes')->where('id', $scope->id)->whereNull('ended_at')->first(['division_id', 'campus_id']);

        if ($class === null) {
            return null;
        }

        $division = Row::text($class, 'division_id');
        $campus = Row::text($class, 'campus_id');
        $offering = $this->school->offeringId($division, $campus);

        return $offering !== null
            ? [ScopeRef::of(ScopeType::Offering, $offering)]
            : [ScopeRef::of(ScopeType::Division, $division), ScopeRef::of(ScopeType::Campus, $campus)];
    }

    public function label(ScopeRef $scope): ?string
    {
        $row = $this->classes()->where('k.id', $scope->id)->first(['k.name', 'c.name as campus']);

        return $row === null ? null : $this->name($row);
    }

    /** The classes of the current year. */
    public function places(): array
    {
        $year = $this->calendar->currentYear();

        if ($year === null) {
            return [];
        }

        $places = [];

        foreach ($this->classes()->where('k.year_id', $year['id'])->whereNull('k.ended_at')
            ->orderBy('d.position')->orderBy('c.position')->orderBy('k.position')->get(['k.id', 'k.name', 'c.name as campus']) as $row) {
            $places[] = ['scope' => ScopeRef::of(ScopeType::ClassGroup, Row::text($row, 'id')), 'label' => $this->name($row)];
        }

        return $places;
    }

    private function classes(): Builder
    {
        return DB::table('academic_classes as k')
            ->join('institution_divisions as d', 'd.id', '=', 'k.division_id')
            ->join('institution_campuses as c', 'c.id', '=', 'k.campus_id');
    }

    private ?bool $severalCampuses = null;

    private function name(object $row): string
    {
        $this->severalCampuses ??= count($this->school->campusViews()) > 1;

        return $this->severalCampuses ? Row::text($row, 'name').' · '.Row::text($row, 'campus') : Row::text($row, 'name');
    }
}
