<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Application;

use Acadly\Kernel\Access\ScopeParents;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * The places the Institution domain owns in the scope tree: divisions and
 * campuses sit in the school; an offering (a division taught at a campus) sits
 * in both its division and its campus. Ended places are not in force.
 */
final class InstitutionScopes implements ScopeParents
{
    public function types(): array
    {
        return [ScopeType::Division, ScopeType::Campus, ScopeType::Offering];
    }

    public function parents(ScopeRef $scope): ?array
    {
        return match ($scope->type) {
            ScopeType::Division => DB::table('institution_divisions')->where('id', $scope->id)->whereNull('ended_at')->exists() ? [] : null,
            ScopeType::Campus => DB::table('institution_campuses')->where('id', $scope->id)->whereNull('ended_at')->exists() ? [] : null,
            ScopeType::Offering => $this->offering((string) $scope->id),
            default => null,
        };
    }

    /** @return list<ScopeRef>|null */
    private function offering(string $id): ?array
    {
        $row = DB::table('institution_offerings as o')
            ->join('institution_divisions as d', 'd.id', '=', 'o.division_id')
            ->join('institution_campuses as c', 'c.id', '=', 'o.campus_id')
            ->where('o.id', $id)->whereNull('o.ended_at')->whereNull('d.ended_at')->whereNull('c.ended_at')
            ->first(['o.division_id', 'o.campus_id']);

        return $row === null ? null : [
            ScopeRef::of(ScopeType::Division, Row::text($row, 'division_id')),
            ScopeRef::of(ScopeType::Campus, Row::text($row, 'campus_id')),
        ];
    }
}
