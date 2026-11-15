<?php

declare(strict_types=1);

use Acadly\Core\Institution\Contracts\InstitutionPermission;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Actions\AssignRole;
use Acadly\Kernel\Access\Actions\EndRoleAssignment;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeHierarchy;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()]);
    app()->forgetScopedInstances();
    $this->school = TwoCampusSchool::make();
});

/** A role named anything at all: power is in its permissions. @param  list<Permission>  $permissions */
function namedRole(string $name, array $permissions): Role
{
    $role = Role::query()->create(['key' => 'r_'.substr(Ids::next(), -12), 'name' => $name]);
    $role->grant($permissions);

    return $role;
}

function holdAt(User $user, Role $role, ScopeRef $at): RoleAssignment
{
    return RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $at->type,
        'scope_id' => $at->id, 'starts_at' => now()->subMinute()]);
}

function treeAccess(): Access
{
    app()->forgetScopedInstances();

    return app(Access::class);
}

it('walks a class up through its offering, its level and its campus to the school', function (): void {
    $ancestors = app(ScopeHierarchy::class)->ancestors($this->school->scope(ScopeType::ClassGroup, 'class_primary_a'));

    expect(array_map(fn (ScopeRef $s): string => $s->type->value.':'.($s->id ?? ''), $ancestors))->toBe([
        'offering:'.$this->school->id('offering_primary_a'),
        'division:'.$this->school->id('primary'),
        'campus:'.$this->school->id('campus_a'),
        'institution:',
    ]);
});

it('limits a role at a level at one campus to that pair and what is inside it', function (): void {
    $teacher = User::factory()->create();
    holdAt($teacher, namedRole('Primary lead, Area 47', [AttendancePermission::Capture]), $this->school->scope(ScopeType::Offering, 'offering_primary_a'));
    $access = treeAccess();

    expect($access->allows($teacher, AttendancePermission::Capture, $this->school->scope(ScopeType::ClassGroup, 'class_primary_a')))->toBeTrue()
        ->and($access->allows($teacher, AttendancePermission::Capture, $this->school->scope(ScopeType::ClassGroup, 'class_primary_b')))->toBeFalse()
        ->and($access->allows($teacher, AttendancePermission::Capture, $this->school->scope(ScopeType::Division, 'primary')))->toBeFalse()
        ->and($access->allows($teacher, AttendancePermission::Capture, $this->school->scope(ScopeType::Campus, 'campus_a')))->toBeFalse()
        ->and($access->allows($teacher, AttendancePermission::Capture))->toBeFalse();
});

it('gives a level role every campus of that level, and a campus role every level at that campus', function (): void {
    $level = User::factory()->create();
    $campus = User::factory()->create();
    holdAt($level, namedRole('Head of Primary', [AttendancePermission::Capture]), $this->school->scope(ScopeType::Division, 'primary'));
    holdAt($campus, namedRole('Blantyre manager', [AttendancePermission::Capture]), $this->school->scope(ScopeType::Campus, 'campus_b'));
    $access = treeAccess();

    expect($access->allows($level, AttendancePermission::Capture, $this->school->scope(ScopeType::ClassGroup, 'class_primary_a')))->toBeTrue()
        ->and($access->allows($level, AttendancePermission::Capture, $this->school->scope(ScopeType::ClassGroup, 'class_primary_b')))->toBeTrue()
        ->and($access->allows($level, AttendancePermission::Capture, $this->school->scope(ScopeType::ClassGroup, 'class_secondary_b')))->toBeFalse()
        ->and($access->allows($campus, AttendancePermission::Capture, $this->school->scope(ScopeType::ClassGroup, 'class_secondary_b')))->toBeTrue()
        ->and($access->allows($campus, AttendancePermission::Capture, $this->school->scope(ScopeType::ClassGroup, 'class_primary_b')))->toBeTrue()
        ->and($access->allows($campus, AttendancePermission::Capture, $this->school->scope(ScopeType::ClassGroup, 'class_primary_a')))->toBeFalse();
});

it('gives no role at a place that has ended, and lets a whole-school role still reach it', function (): void {
    $by = User::factory()->create();
    holdAt($by, namedRole('Office', [AccessPermission::AssignRoles, AttendancePermission::Capture]), ScopeRef::institution());
    DB::table('institution_campuses')->where('id', $this->school->id('campus_b'))->update(['ended_at' => now()]);
    $ended = $this->school->scope(ScopeType::Campus, 'campus_b');

    expect(fn () => app(AssignRole::class)->handle($by, User::factory()->create(), namedRole('Clerk', [AttendancePermission::Capture]), $ended))
        ->toThrow(ValidationException::class, 'does not exist or has ended')
        ->and(treeAccess()->allows($by, AttendancePermission::Capture, $ended))->toBeTrue()
        ->and(app(ScopeHierarchy::class)->exists($ended))->toBeFalse();
});

describe('power comes from permissions, never from the name of a role', function (): void {
    it('counts whoever can give roles for the whole school, whatever the role is called', function (): void {
        $bursar = User::factory()->create();
        $assignment = holdAt($bursar, namedRole('Bursar', [AccessPermission::AssignRoles]), ScopeRef::institution());
        holdAt(User::factory()->create(), namedRole('Campus office', [AccessPermission::AssignRoles]), $this->school->scope(ScopeType::Campus, 'campus_a'));

        expect(treeAccess()->holders(AccessPermission::AssignRoles))->toBe(1)
            ->and(treeAccess()->holders(AccessPermission::AssignRoles, $this->school->scope(ScopeType::ClassGroup, 'class_primary_a')))->toBe(2)
            ->and(treeAccess()->holders(AccessPermission::AssignRoles, except: $assignment->id))->toBe(0);
    });

    it('refuses to end the last whole-school role giver, even when the role is not the system role', function (): void {
        $only = User::factory()->create();
        $assignment = holdAt($only, namedRole('Proprietor', [AccessPermission::AssignRoles, InstitutionPermission::Manage]), ScopeRef::institution());

        expect(fn () => app(EndRoleAssignment::class)->handle($only, $assignment, 'Leaving.'))
            ->toThrow(ValidationException::class, 'last account that can give roles');
    });

    it('ends the system role when another account can give roles under another name', function (): void {
        $admin = User::factory()->create();
        $system = holdAt($admin, Role::query()->where('key', Role::ADMINISTRATOR)->firstOrFail(), ScopeRef::institution());
        holdAt(User::factory()->create(), namedRole('Director', [AccessPermission::AssignRoles]), ScopeRef::institution());

        app(EndRoleAssignment::class)->handle($admin, $system, 'Handed over to the director.');

        expect($system->fresh()?->ends_at)->not->toBeNull();
    });
});
