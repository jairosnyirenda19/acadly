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

    public function label(ScopeRef $scope): ?string
    {
        return match ($scope->type) {
            ScopeType::Division => self::name(DB::table('institution_divisions')->where('id', $scope->id)->value('name')),
            ScopeType::Campus => self::name(DB::table('institution_campuses')->where('id', $scope->id)->value('name')),
            ScopeType::Offering => $this->offeringLabel((string) $scope->id),
            default => null,
        };
    }

    /**
     * Levels, then campuses, then a level at one campus where a level is
     * taught at more than one campus.
     */
    public function places(): array
    {
        $places = [];

        foreach (DB::table('institution_divisions')->whereNull('ended_at')->orderBy('position')->get(['id', 'name']) as $row) {
            $places[] = ['scope' => ScopeRef::of(ScopeType::Division, Row::text($row, 'id')), 'label' => Row::text($row, 'name')];
        }

        foreach (DB::table('institution_campuses')->whereNull('ended_at')->orderBy('position')->get(['id', 'name']) as $row) {
            $places[] = ['scope' => ScopeRef::of(ScopeType::Campus, Row::text($row, 'id')), 'label' => Row::text($row, 'name')];
        }

        $offerings = $this->offerings();
        $campusesOf = array_count_values(array_column($offerings, 'division_id'));

        foreach ($offerings as $offering) {
            if (($campusesOf[$offering['division_id']] ?? 0) > 1) {
                $places[] = ['scope' => ScopeRef::of(ScopeType::Offering, $offering['id']), 'label' => $offering['label']];
            }
        }

        return $places;
    }

    /** @return list<array{id: string, division_id: string, label: string}> */
    private function offerings(): array
    {
        return array_values(DB::table('institution_offerings as o')
            ->join('institution_divisions as d', 'd.id', '=', 'o.division_id')
            ->join('institution_campuses as c', 'c.id', '=', 'o.campus_id')
            ->whereNull('o.ended_at')->whereNull('d.ended_at')->whereNull('c.ended_at')
            ->orderBy('d.position')->orderBy('c.position')
            ->get(['o.id', 'o.division_id', 'd.name as division', 'c.name as campus'])
            ->map(fn (object $row): array => [
                'id' => Row::text($row, 'id'),
                'division_id' => Row::text($row, 'division_id'),
                'label' => Row::text($row, 'division').' · '.Row::text($row, 'campus'),
            ])->all());
    }

    private function offeringLabel(string $id): ?string
    {
        $row = DB::table('institution_offerings as o')
            ->join('institution_divisions as d', 'd.id', '=', 'o.division_id')
            ->join('institution_campuses as c', 'c.id', '=', 'o.campus_id')
            ->where('o.id', $id)->first(['d.name as division', 'c.name as campus']);

        return $row === null ? null : Row::text($row, 'division').' · '.Row::text($row, 'campus');
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

    private static function name(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
