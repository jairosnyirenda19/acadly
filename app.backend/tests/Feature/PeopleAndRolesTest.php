<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()]);
    app()->forgetScopedInstances();
    Notification::fake();
    $this->school = TwoCampusSchool::make();
    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
});

/** @param  list<string>  $permissions */
function peopleRole(string $name, array $permissions): Role
{
    $role = Role::query()->create(['key' => 'p_'.substr(Ids::next(), -10), 'name' => $name]);
    DB::table('access_role_permissions')->insert(array_map(fn (string $key): array => ['role_id' => $role->id, 'permission_key' => $key], $permissions));

    return $role;
}

function holdRole(User $user, Role $role, ScopeRef $at): RoleAssignment
{
    return RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $at->type,
        'scope_id' => $at->id, 'starts_at' => now()->subMinute()]);
}

describe('people', function (): void {
    it('lists each person with the roles they hold and where, their state, and who can give roles for the whole school', function (): void {
        $teacher = peopleRole('Class teacher', [AttendancePermission::Capture->value]);
        $mercy = User::factory()->create(['name' => 'Mercy Banda']);
        holdRole($mercy, $teacher, $this->school->scope(ScopeType::ClassGroup, 'class_primary_a'));
        User::factory()->create(['name' => 'Lusungu Gondwe']);
        $expired = User::factory()->create(['name' => 'Tiyamike Phiri', 'status' => AccountStatus::Invited]);
        DB::table('users')->where('id', $expired->id)->update(['invitation_sent_at' => now()->subDays(9)]);
        holdRole($expired, $teacher, $this->school->scope(ScopeType::ClassGroup, 'class_primary_b'));

        $list = $this->actingAs($this->admin)->getJson('/api/v1/people')->assertOk()->json();
        $byName = collect($list['people'])->keyBy('name');

        expect($byName['Mercy Banda']['state'])->toBe('active')
            ->and($byName['Mercy Banda']['roles'][0])->toMatchArray(['role' => 'Class teacher', 'where' => 'STD1 · Area 47'])
            ->and($byName['Lusungu Gondwe']['state'])->toBe('no_role')
            ->and($byName['Tiyamike Phiri']['state'])->toBe('expired')
            ->and($list['totals']['whole_school_givers'])->toBe(['Grace Phiri'])
            ->and(collect($list['giving']['places'])->pluck('label')->all())->toContain('Whole school', 'Primary · Area 47', 'STD1 · Blantyre');
    });

    it('invites a person with a role at a level at one campus', function (): void {
        $teacher = peopleRole('Class teacher', [AttendancePermission::Capture->value]);

        $person = $this->actingAs($this->admin)->postJson('/api/v1/people', [
            'name' => 'Kondwani Chirwa', 'email' => 'k.chirwa@example.mw', 'role_id' => $teacher->id,
            'scope_type' => 'offering', 'scope_id' => $this->school->id('offering_primary_b'), 'send' => false,
        ])->assertOk()->json();

        expect($person['person']['state'])->toBe('ready')
            ->and($person['held'][0])->toMatchArray(['role' => 'Class teacher', 'where' => 'Primary · Blantyre']);
    });

    it('gives a role for a period with a reason, and refuses a role holding more than the giver holds there', function (): void {
        $teacher = peopleRole('Class teacher', [AttendancePermission::Capture->value]);
        $head = peopleRole('Head teacher', [AttendancePermission::Capture->value, AttendancePermission::Correct->value]);
        $giver = User::factory()->create();
        holdRole($giver, peopleRole('Campus office', [AccessPermission::AssignRoles->value, AttendancePermission::Capture->value]), $this->school->scope(ScopeType::Campus, 'campus_a'));
        $person = User::factory()->create();
        holdRole($person, $teacher, $this->school->scope(ScopeType::ClassGroup, 'class_primary_a'));
        $at = ['scope_type' => 'class', 'scope_id' => $this->school->id('class_primary_a')];

        $this->actingAs($giver)->postJson("/api/v1/people/{$person->id}/roles", [...$at, 'role_id' => $teacher->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->actingAs($giver)->postJson("/api/v1/people/{$person->id}/roles", [...$at, 'role_id' => $head->id, 'reason' => 'Acting.'])
            ->assertForbidden();

        $shown = $this->actingAs($giver)->postJson("/api/v1/people/{$person->id}/roles", [...$at, 'role_id' => $teacher->id,
            'ends_on' => now()->addMonth()->toDateString(), 'reason' => 'Cover for Term 1.'])->assertOk()->json();

        expect(collect($shown['held'])->firstWhere('reason', 'Cover for Term 1.'))->not->toBeNull()
            ->and($shown['can_do'][0]['permissions'][0]['label'])->toBe('Take the register');
    });

    it('ends a role with a reason and keeps it in the history', function (): void {
        $teacher = peopleRole('Class teacher', [AttendancePermission::Capture->value]);
        $person = User::factory()->create();
        $held = holdRole($person, $teacher, $this->school->scope(ScopeType::ClassGroup, 'class_primary_a'));

        $shown = $this->actingAs($this->admin)->postJson("/api/v1/people/{$person->id}/roles/{$held->id}/end", ['reason' => 'Moved to the office.'])
            ->assertOk()->json();

        expect($shown['held'])->toBe([])
            ->and($shown['ended'][0])->toMatchArray(['role' => 'Class teacher', 'ended_by' => 'Grace Phiri', 'end_reason' => 'Moved to the office.']);
    });

    it('disables an account with a reason, and never the last account that can give roles for the whole school', function (): void {
        $teacher = peopleRole('Class teacher', [AttendancePermission::Capture->value]);
        $person = User::factory()->create();
        holdRole($person, $teacher, ScopeRef::institution());

        $this->actingAs($this->admin)->postJson("/api/v1/people/{$person->id}/disable", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $shown = $this->actingAs($this->admin)->postJson("/api/v1/people/{$person->id}/disable", ['reason' => 'Left the school.'])->assertOk()->json();
        app()->forgetScopedInstances();

        expect($shown['person']['disabled']['reason'])->toBe('Left the school.')
            ->and(app(Access::class)->allows($person->fresh(), AttendancePermission::Capture))->toBeFalse();

        $this->actingAs($this->admin)->postJson("/api/v1/people/{$this->admin->id}/disable", ['reason' => 'Test.'])
            ->assertUnprocessable()->assertJsonValidationErrors(['user']);
    });

    it('lets a campus office act on its own staff only, and sends a reset link to the person', function (): void {
        $office = User::factory()->create();
        holdRole($office, peopleRole('Campus office', [AccessPermission::AssignRoles->value, AttendancePermission::Capture->value]), $this->school->scope(ScopeType::Campus, 'campus_a'));
        $teacher = peopleRole('Class teacher', [AttendancePermission::Capture->value]);
        $own = User::factory()->create();
        holdRole($own, $teacher, $this->school->scope(ScopeType::ClassGroup, 'class_primary_a'));
        $shared = User::factory()->create();
        holdRole($shared, $teacher, $this->school->scope(ScopeType::ClassGroup, 'class_primary_a'));
        holdRole($shared, $teacher, $this->school->scope(ScopeType::ClassGroup, 'class_primary_b'));

        $this->actingAs($office)->postJson("/api/v1/people/{$own->id}/password-reset")->assertOk();
        Notification::assertSentTo($own, ResetPassword::class);

        $this->actingAs($office)->getJson("/api/v1/people/{$shared->id}")->assertOk()->assertJsonPath('can_act', false);
        $this->actingAs($office)->postJson("/api/v1/people/{$shared->id}/disable", ['reason' => 'Test.'])->assertForbidden();
    });
});

describe('roles', function (): void {
    it('lists the roles with their permissions and holders, the system role locked', function (): void {
        $roles = $this->actingAs($this->admin)->getJson('/api/v1/roles')->assertOk()->json();

        expect($roles['roles'][0])->toMatchArray(['system' => true, 'people' => 1])
            ->and(collect($roles['groups'])->pluck('name')->all())->toContain('Access', 'Attendance')
            ->and(collect($roles['groups'])->firstWhere('name', 'Access')['permissions'][0])->toHaveKeys(['label', 'help', 'sight', 'yours']);
    });

    it('creates and changes a role, but only with permissions the editor holds', function (): void {
        $roles = $this->actingAs($this->admin)->postJson('/api/v1/roles', ['name' => 'Bursar', 'permissions' => [AttendancePermission::Capture->value]])->assertOk()->json();
        $bursar = collect($roles['roles'])->firstWhere('name', 'Bursar');

        $this->actingAs($this->admin)->putJson("/api/v1/roles/{$bursar['id']}", ['name' => 'Bursar', 'permissions' => [AttendancePermission::Capture->value, AttendancePermission::Correct->value]])
            ->assertOk()->assertJsonPath('roles.1.permissions', [AttendancePermission::Capture->value, AttendancePermission::Correct->value]);

        $editor = User::factory()->create();
        holdRole($editor, peopleRole('Role editor', [AccessPermission::ManageRoles->value, AttendancePermission::Capture->value]), ScopeRef::institution());
        $this->actingAs($editor)->putJson("/api/v1/roles/{$bursar['id']}", ['name' => 'Bursar', 'permissions' => [AttendancePermission::Capture->value, AttendancePermission::Correct->value, 'access.roles.assign']])
            ->assertForbidden();

        $system = Role::query()->where('is_system', true)->value('id');
        $this->actingAs($this->admin)->putJson("/api/v1/roles/{$system}", ['name' => 'Everything', 'permissions' => []])->assertUnprocessable();
    });

    it('refuses a change that would leave nobody who can give roles for the whole school', function (): void {
        $director = peopleRole('Director', [AccessPermission::AssignRoles->value, AccessPermission::ManageRoles->value]);
        $only = User::factory()->create();
        holdRole($only, $director, ScopeRef::institution());
        DB::table('access_role_assignments')->where('user_id', $this->admin->id)->update(['ends_at' => now()->subSecond()]);
        app()->forgetScopedInstances();

        $this->actingAs($only)->putJson("/api/v1/roles/{$director->id}", ['name' => 'Director', 'permissions' => [AccessPermission::ManageRoles->value]])
            ->assertUnprocessable()->assertJsonValidationErrors(['permissions']);
    });
});
