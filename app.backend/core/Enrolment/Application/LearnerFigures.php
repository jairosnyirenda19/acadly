<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Enrolment\Contracts\DivisionFigures;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/** How many learners each division has now, for those who may see its learners (D-096). */
final class LearnerFigures implements DivisionFigures
{
    public function __construct(private readonly Access $access) {}

    public function key(): string
    {
        return 'enrolment';
    }

    public function figures(User $viewer, array $divisionIds, string $day): array
    {
        $seen = array_values(array_filter($divisionIds, fn (string $id): bool => $this->access->holdsForCount($viewer, EnrolmentPermission::View, ScopeRef::of(ScopeType::Division, $id))));

        if ($seen === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('placements')->whereIn('division_id', $seen)->whereNull('ends_on')
            ->groupBy('division_id')->selectRaw('division_id, count(distinct learner_id) as n')->get() as $row) {
            $out[Row::text($row, 'division_id')] = ['learners' => Row::int($row, 'n')];
        }

        foreach ($seen as $id) {
            $out[$id] ??= ['learners' => 0];
        }

        return $out;
    }
}
