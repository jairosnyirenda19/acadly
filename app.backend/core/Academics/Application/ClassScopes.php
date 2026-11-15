<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application;

use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\ScopeParents;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * Classes in the scope tree: a class in force sits in the offering of its
 * division at its campus. When that offering has ended the class still sits
 * in its division and its campus.
 */
final class ClassScopes implements ScopeParents
{
    public function __construct(private readonly SchoolDirectory $school) {}

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
}
