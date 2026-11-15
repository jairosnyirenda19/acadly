<?php

declare(strict_types=1);

use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()]);
    app()->forgetScopedInstances();
    $this->school = TwoCampusSchool::make();
});

/** @param  list<string>  $permissions */
function workRole(string $name, array $permissions): Role
{
    $role = Role::query()->create(['key' => 'w_'.substr(Ids::next(), -10), 'name' => $name]);
    DB::table('access_role_permissions')->insert(array_map(fn (string $key): array => ['role_id' => $role->id, 'permission_key' => $key], $permissions));

    return $role;
}

function holdHere(User $user, Role $role, ScopeRef $at, ?string $endsAt = null): void
{
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $at->type,
        'scope_id' => $at->id, 'starts_at' => now()->subMinute(), 'ends_at' => $endsAt]);
}

it('lists where a person can work: the places of their roles and what is inside them', function (): void {
    $esther = User::factory()->create();
    holdHere($esther, workRole('Head of Primary', [AttendancePermission::Capture->value]), $this->school->scope(ScopeType::Division, 'primary'), now()->addMonth()->toIso8601String());

    $places = collect($this->actingAs($esther)->getJson('/api/v1/me/context')->assertOk()->json('places'))->keyBy('label');

    expect($places->keys()->all())->toBe(['Primary', 'Primary · Area 47', 'Primary · Blantyre', 'STD1 · Area 47', 'STD1 · Blantyre'])
        ->and($places['Primary']['held'])->toBeTrue()
        ->and($places['Primary']['roles'][0]['role'])->toBe('Head of Primary')
        ->and($places['Primary']['roles'][0]['ends_at'])->not->toBeNull()
        ->and($places['STD1 · Blantyre']['held'])->toBeFalse()
        ->and($places['STD1 · Blantyre']['within'])->toContain('division:'.$this->school->id('primary'), 'campus:'.$this->school->id('campus_b'), 'institution:');
});

it('narrows the people list to the place chosen, and refuses a place the viewer does not work in', function (): void {
    $office = User::factory()->create();
    holdHere($office, workRole('Office', [AccessPermission::AssignRoles->value, AttendancePermission::Capture->value]), ScopeRef::institution());
    $campusOffice = User::factory()->create();
    holdHere($campusOffice, workRole('Campus office', [AccessPermission::AssignRoles->value]), $this->school->scope(ScopeType::Campus, 'campus_a'));
    $teacher = workRole('Class teacher', [AttendancePermission::Capture->value]);
    $atA = User::factory()->create(['name' => 'Teacher at A']);
    holdHere($atA, $teacher, $this->school->scope(ScopeType::ClassGroup, 'class_primary_a'));
    $atB = User::factory()->create(['name' => 'Teacher at B']);
    holdHere($atB, $teacher, $this->school->scope(ScopeType::ClassGroup, 'class_secondary_b'));

    $names = collect($this->actingAs($office)->getJson('/api/v1/people?in=campus:'.$this->school->id('campus_a'))->assertOk()->json('people'))->pluck('name');

    expect($names)->toContain('Teacher at A')->not->toContain('Teacher at B');

    $this->actingAs($campusOffice)->getJson('/api/v1/people?in=campus:'.$this->school->id('campus_b'))->assertNotFound();
    $this->actingAs($campusOffice)->getJson('/api/v1/people?in=nonsense')->assertNotFound();
});
