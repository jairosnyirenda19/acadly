<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Actions\AssignRole;
use Acadly\Kernel\Access\Actions\EndRoleAssignment;
use Acadly\Kernel\Access\FlatScopeHierarchy;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\BadPrefixModule;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
});

/** @param  list<Permission>  $permissions */
function roleWith(array $permissions, string $key = ''): Role
{
    $role = Role::query()->create(['key' => $key !== '' ? $key : 'role_'.substr(Ids::next(), -12), 'name' => 'Test role']);
    $role->grant($permissions);

    return $role;
}

function hold(User $user, Role $role, ScopeRef $at, ?Carbon $startsAt = null, ?Carbon $endsAt = null): RoleAssignment
{
    return RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'scope_type' => $at->type,
        'scope_id' => $at->id,
        'starts_at' => $startsAt ?? now()->subMinute(),
        'ends_at' => $endsAt,
    ]);
}

function campus(): ScopeRef
{
    return ScopeRef::of(ScopeType::Campus, Ids::next());
}

function access(): Access
{
    return app(Access::class);
}

it('denies an account that holds no role', function (): void {
    $user = User::factory()->create();

    expect(access()->allows($user, AttendancePermission::Capture))->toBeFalse()
        ->and(access()->allows(null, AttendancePermission::Capture))->toBeFalse();
});

it('applies an institution-wide role at every campus', function (): void {
    $user = User::factory()->create();
    hold($user, roleWith([AttendancePermission::Capture]), ScopeRef::institution());

    expect(access()->allows($user, AttendancePermission::Capture))->toBeTrue()
        ->and(access()->allows($user, AttendancePermission::Capture, campus()))->toBeTrue()
        ->and(access()->allows($user, AttendancePermission::Correct))->toBeFalse();
});

it('limits a campus role to that campus', function (): void {
    $user = User::factory()->create();
    $blantyre = campus();
    hold($user, roleWith([AttendancePermission::Capture]), $blantyre);

    expect(access()->allows($user, AttendancePermission::Capture, $blantyre))->toBeTrue()
        ->and(access()->allows($user, AttendancePermission::Capture, campus()))->toBeFalse()
        ->and(access()->allows($user, AttendancePermission::Capture, ScopeRef::institution()))->toBeFalse();
});

it('ignores assignments that have ended or not yet started', function (): void {
    $ended = User::factory()->create();
    $future = User::factory()->create();
    $role = roleWith([AttendancePermission::Capture]);

    hold($ended, $role, ScopeRef::institution(), now()->subDays(10), now()->subDay());
    hold($future, $role, ScopeRef::institution(), now()->addDay());

    expect(access()->allows($ended, AttendancePermission::Capture))->toBeFalse()
        ->and(access()->allows($future, AttendancePermission::Capture))->toBeFalse();
});

it('denies a disabled account whatever it holds', function (): void {
    $user = User::factory()->disabled()->create();
    hold($user, Role::query()->where('key', Role::ADMINISTRATOR)->firstOrFail(), ScopeRef::institution());

    expect(access()->allows($user, AttendancePermission::Capture))->toBeFalse();
});

it('refuses with a 403 problem document', function (): void {
    expect(fn () => access()->check(User::factory()->create(), AttendancePermission::Correct))
        ->toThrow(AuthorizationException::class);

    Route::get('/api/v1/probe-refusal', fn () => access()->check(null, AttendancePermission::Correct));

    $this->getJson('/api/v1/probe-refusal')->assertStatus(403)->assertExactJson([
        'type' => 'about:blank',
        'title' => 'Access is refused.',
        'status' => 403,
        'code' => 'access_refused',
    ]);
});

it('lists grants on /me', function (): void {
    $user = User::factory()->create();
    $blantyre = campus();
    hold($user, roleWith([AttendancePermission::Capture]), $blantyre);

    $this->actingAs($user)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('grants', [[
            'permission' => 'attendance.capture',
            'scope_type' => 'campus',
            'scope_id' => $blantyre->id,
        ]]);
});

describe('giving a role', function (): void {
    it('needs the permission to give roles', function (): void {
        $by = User::factory()->create();
        hold($by, roleWith([AttendancePermission::Capture]), ScopeRef::institution());

        app(AssignRole::class)->handle($by, User::factory()->create(), roleWith([AttendancePermission::Capture]), ScopeRef::institution());
    })->throws(AuthorizationException::class);

    it('cannot give a permission the assigner does not hold', function (): void {
        $by = User::factory()->create();
        $blantyre = campus();
        hold($by, roleWith([AccessPermission::AssignRoles, AttendancePermission::Capture]), $blantyre);

        app(AssignRole::class)->handle($by, User::factory()->create(), roleWith([AttendancePermission::Correct]), $blantyre);
    })->throws(AuthorizationException::class, 'does not hold');

    it('cannot give a role at a scope the assigner does not hold', function (): void {
        $by = User::factory()->create();
        hold($by, roleWith([AccessPermission::AssignRoles, AttendancePermission::Capture]), campus());

        app(AssignRole::class)->handle($by, User::factory()->create(), roleWith([AttendancePermission::Capture]), campus());
    })->throws(AuthorizationException::class);

    it('gives a role within the assigner\'s own scope and permissions', function (): void {
        $by = User::factory()->create();
        $to = User::factory()->create();
        $blantyre = campus();
        hold($by, roleWith([AccessPermission::AssignRoles, AttendancePermission::Capture]), $blantyre);

        $assignment = app(AssignRole::class)->handle($by, $to, roleWith([AttendancePermission::Capture]), $blantyre, reason: 'Class teacher, Form 2 East');

        expect($assignment->granted_by)->toBe($by->id)
            ->and(access()->allows($to, AttendancePermission::Capture, $blantyre))->toBeTrue();
    });

    it('refuses a disabled account and an end date in the past', function (): void {
        $by = User::factory()->create();
        hold($by, Role::query()->where('key', Role::ADMINISTRATOR)->firstOrFail(), ScopeRef::institution());
        $role = roleWith([AttendancePermission::Capture]);

        expect(fn () => app(AssignRole::class)->handle($by, User::factory()->disabled()->create(), $role, ScopeRef::institution()))
            ->toThrow(ValidationException::class)
            ->and(fn () => app(AssignRole::class)->handle($by, User::factory()->create(), $role, ScopeRef::institution(), now()->subDay()->toImmutable()))
            ->toThrow(ValidationException::class);
    });
});

describe('ending a role', function (): void {
    it('keeps the row with who ended it and why', function (): void {
        $administrator = Role::query()->where('key', Role::ADMINISTRATOR)->firstOrFail();
        $by = User::factory()->create();
        hold($by, $administrator, ScopeRef::institution());
        $teacher = User::factory()->create();
        $assignment = hold($teacher, roleWith([AttendancePermission::Capture]), ScopeRef::institution());

        app(EndRoleAssignment::class)->handle($by, $assignment, 'Left the school.');

        $row = $assignment->fresh();
        expect($row?->ended_by)->toBe($by->id)
            ->and($row?->end_reason)->toBe('Left the school.')
            ->and(access()->allows($teacher, AttendancePermission::Capture))->toBeFalse();
    });

    it('refuses to remove the last administrator', function (): void {
        $administrator = Role::query()->where('key', Role::ADMINISTRATOR)->firstOrFail();
        $only = User::factory()->create();
        $assignment = hold($only, $administrator, ScopeRef::institution());

        app(EndRoleAssignment::class)->handle($only, $assignment, 'Handing over.');
    })->throws(ValidationException::class);
});

describe('the catalogue and sync', function (): void {
    it('mirrors every permission in the database and gives all of them to the administrator role', function (): void {
        $keys = array_keys(app(PermissionCatalogue::class)->all());
        $administrator = Role::query()->where('key', Role::ADMINISTRATOR)->firstOrFail();

        expect(DB::table('access_permissions')->orderBy('key')->pluck('key')->all())->toBe(collect($keys)->sort()->values()->all())
            ->and($administrator->permissionKeys())->toBe(collect($keys)->sort()->values()->all())
            ->and($administrator->is_system)->toBeTrue()
            ->and(DB::table('access_permissions')->where('key', 'attendance.all_classes')->value('is_sight'))->toBeTrue();
    });

    it('removes a permission that left the code, and its role links', function (): void {
        DB::table('access_permissions')->insert(['key' => 'attendance.retired', 'owner' => 'attendance', 'label' => 'Retired', 'created_at' => now(), 'updated_at' => now()]);
        $role = roleWith([AttendancePermission::Capture]);
        DB::table('access_role_permissions')->insert(['role_id' => $role->id, 'permission_key' => 'attendance.retired']);

        $this->artisan('acadly:sync')->assertSuccessful();

        expect(DB::table('access_permissions')->where('key', 'attendance.retired')->exists())->toBeFalse()
            ->and($role->permissionKeys())->toBe(['attendance.capture']);
    });

    it('refuses a permission whose key does not start with its module key', function (): void {
        new PermissionCatalogue(new ModuleRegistry([new BadPrefixModule]));
    })->throws(LogicException::class, 'must start with');

    it('refuses an unknown permission at check time', function (): void {
        $catalogue = new PermissionCatalogue(new ModuleRegistry([]));
        $access = new Access($catalogue, new FlatScopeHierarchy, new Clock);

        $access->allows(User::factory()->create(), AttendancePermission::Capture);
    })->throws(LogicException::class, 'not a permission in this build');
});

describe('the first administrator', function (): void {
    it('creates the first administrator once, and only once', function (): void {
        $this->artisan('acadly:administrator', ['email' => 'Head@Example.org', '--name' => 'Agnes Banda'])
            ->expectsQuestion('Password (at least 12 characters)', 'a-long-enough-password')
            ->expectsQuestion('Confirm the password', 'a-long-enough-password')
            ->assertSuccessful();

        $head = User::query()->where('email', 'head@example.org')->firstOrFail();
        expect(access()->allows($head, AccessPermission::AssignRoles))->toBeTrue();

        $this->artisan('acadly:administrator', ['email' => 'second@example.org', '--name' => 'Somebody'])
            ->assertFailed();
    });
});
