<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Enrolment\Contracts\DivisionFigures;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Journeys\Review\State;
use Illuminate\Support\Facades\DB;

/** Progress reviews waiting to be checked, on the Divisions start screen, for those who check them (D-096). */
final class ReviewFigures implements DivisionFigures
{
    public function __construct(
        private readonly Access $access,
        private readonly Reach $reach,
    ) {}

    public function key(): string
    {
        return 'assessment';
    }

    public function figures(User $viewer, array $divisionIds, string $day): array
    {
        $seen = array_values(array_filter($divisionIds, fn (string $id): bool => ! $this->reach->takesMarks($id)
            && $this->access->allows($viewer, AssessmentPermission::Review, ScopeRef::of(ScopeType::Division, $id))));

        if ($seen === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('progress_reviews')->whereIn('division_id', $seen)->where('state', State::Ready->value)
            ->groupBy('division_id')->selectRaw('division_id, count(*) as n')->get() as $row) {
            $n = Row::int($row, 'n');
            $out[Row::text($row, 'division_id')] = ['state' => ['tone' => 'attention', 'text' => $n === 1 ? '1 review to check' : "{$n} reviews to check"]];
        }

        return $out;
    }
}
