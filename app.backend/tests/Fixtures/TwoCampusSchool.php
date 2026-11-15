<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * A school with two campuses (A and B), Primary taught at both and Secondary
 * at B only, a year, and one class of each offering. Written straight to the
 * tables: these tests are about places, not about setup.
 */
final class TwoCampusSchool
{
    /** @var array<string, string> name => id */
    public array $ids = [];

    public static function make(): self
    {
        $school = new self;
        $now = now();

        foreach (['primary' => 'PRIMARY@2026.1', 'secondary' => 'SECONDARY@2026.1'] as $key => $profile) {
            $school->ids[$key] = Ids::next();
            DB::table('institution_divisions')->insert(['id' => $school->ids[$key], 'name' => ucfirst($key), 'profile_ref' => $profile,
                'position' => count($school->ids), 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach (['a' => 'Area 47', 'b' => 'Blantyre'] as $key => $name) {
            $school->ids["campus_{$key}"] = Ids::next();
            DB::table('institution_campuses')->insert(['id' => $school->ids["campus_{$key}"], 'name' => $name, 'place' => $name,
                'position' => count($school->ids), 'created_at' => $now, 'updated_at' => $now]);
        }

        $school->ids['year'] = Ids::next();
        DB::table('academic_years')->insert(['id' => $school->ids['year'], 'name' => '2026/27', 'starts_on' => '2026-09-07', 'ends_on' => '2027-07-23',
            'created_at' => $now, 'updated_at' => $now]);

        foreach (['primary_a' => ['primary', 'campus_a', 'STD1'], 'primary_b' => ['primary', 'campus_b', 'STD1'], 'secondary_b' => ['secondary', 'campus_b', 'F1']] as $key => [$division, $campus, $level]) {
            $school->ids["offering_{$key}"] = Ids::next();
            DB::table('institution_offerings')->insert(['id' => $school->ids["offering_{$key}"], 'division_id' => $school->ids[$division],
                'campus_id' => $school->ids[$campus], 'started_at' => $now]);

            $school->ids["class_{$key}"] = Ids::next();
            DB::table('academic_classes')->insert(['id' => $school->ids["class_{$key}"], 'year_id' => $school->ids['year'],
                'division_id' => $school->ids[$division], 'campus_id' => $school->ids[$campus], 'level_code' => $level, 'stream' => null,
                'name' => $level, 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }

        return $school;
    }

    public function scope(ScopeType $type, string $name): ScopeRef
    {
        return ScopeRef::of($type, $this->ids[$name]);
    }

    public function id(string $name): string
    {
        return $this->ids[$name];
    }
}
