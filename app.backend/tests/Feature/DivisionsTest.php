<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Fixtures\TwoCampusSchool;

/* The Divisions start screen (D-096): only the divisions a person works in, and only figures they may see. */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    $this->school = TwoCampusSchool::make();
    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
});

it('gives the whole school every division, with its learners, and the shared tiles', function (): void {
    $data = $this->actingAs($this->admin)->getJson('/api/v1/divisions/overview')->assertOk()->json();

    expect(array_column($data['divisions'], 'name'))->toBe(['Primary', 'Secondary'])
        ->and($data['divisions'][0]['learners'])->toBe(0)
        ->and($data['divisions'][0]['profile'])->toBe('PRIMARY')
        ->and($data['school']['staff'])->toBeGreaterThanOrEqual(1)
        ->and($data['school']['approvals'])->toBe(0);
});

it('gives a teacher of one class only that division, and no figure they may not see', function (): void {
    $teacher = User::factory()->create(['name' => 'Peter Banda']);
    $role = Role::query()->create(['key' => 'div_'.substr(Ids::next(), -12), 'name' => 'Class teacher']);
    $role->grant([EnrolmentPermission::View]);
    RoleAssignment::query()->create(['user_id' => $teacher->id, 'role_id' => $role->id, 'scope_type' => ScopeType::ClassGroup,
        'scope_id' => $this->school->id('class_secondary_b'), 'starts_at' => now()->subMinute()]);

    $data = $this->actingAs($teacher)->getJson('/api/v1/divisions/overview')->assertOk()->json();

    expect(array_column($data['divisions'], 'name'))->toBe(['Secondary'])
        ->and($data['divisions'][0]['learners'])->toBeNull()
        ->and($data['school']['staff'])->toBeNull()
        ->and($data['school']['messages'])->toBeNull();
});
