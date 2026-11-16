<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    // The console's requests are stateful: View as lives in the session.
    $this->withHeader('Origin', 'http://localhost:5173');
    config(['acadly.development.view_as' => true, 'acadly.development.view_as_minutes' => 60]);
    $this->artisan('acadly:sync')->assertSuccessful();
    $this->travelTo('2026-11-17 08:20:00');

    $system = Role::query()->where('is_system', true)->value('id');
    $this->admin = User::factory()->create(['name' => 'Grace Phiri', 'email' => 'grace@example.org']);
    $this->other = User::factory()->create(['name' => 'Peter Admin', 'email' => 'peter@example.org']);

    foreach ([$this->admin, $this->other] as $user) {
        RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $system, 'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    }

    $officer = Role::query()->create(['key' => 'academic_officer', 'name' => 'Academic officer']);
    $officer->grant([EnrolmentPermission::View]);
    $this->martha = User::factory()->create(['name' => 'Martha Kalua', 'email' => 'martha@example.org']);
    RoleAssignment::query()->create(['user_id' => $this->martha->id, 'role_id' => $officer->id, 'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);

    $this->postJson('/api/v1/auth/sign-in', ['email' => 'grace@example.org', 'password' => 'password'])->assertOk()->assertJsonPath('view_as.available', true);
});

it('lists who may be viewed as: never yourself, never an administrator', function (): void {
    $people = $this->getJson('/api/v1/view-as/people')->assertOk();
    expect(array_column($people->json('people'), 'name'))->toBe(['Martha Kalua'])
        ->and($people->json('people.0.roles'))->toBe([['role' => 'Academic officer', 'where' => 'Whole school']]);

    $this->getJson('/api/v1/view-as/people?search=nobody')->assertOk()->assertJsonPath('total', 0);
    $this->postJson("/api/v1/view-as/{$this->other->id}")->assertUnprocessable()->assertJsonValidationErrors(['user' => 'An administrator cannot be viewed as.']);
    $this->postJson("/api/v1/view-as/{$this->admin->id}")->assertUnprocessable();
});

it('views as another account, stamps what is done meanwhile, and leaves', function (): void {
    $as = $this->postJson("/api/v1/view-as/{$this->martha->id}")->assertOk();
    expect($as->json('user.name'))->toBe('Martha Kalua')
        ->and($as->json('view_as.by'))->toBe(['id' => $this->admin->id, 'name' => 'Grace Phiri'])
        ->and($as->json('view_as.ends_at'))->toStartWith('2026-11-17T09:20');

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $this->martha->id);
    // The viewed account is no administrator: it may not start another.
    $this->getJson('/api/v1/view-as/people')->assertForbidden();

    $back = $this->deleteJson('/api/v1/view-as')->assertOk();
    expect($back->json('user.id'))->toBe($this->admin->id)->and($back->json('view_as.by'))->toBeNull();

    $lines = DB::table('audit_events')->whereIn('action', ['identity.view_as_started', 'identity.view_as_ended'])->orderBy('seq')->get();
    expect($lines->pluck('action')->all())->toBe(['identity.view_as_started', 'identity.view_as_ended'])
        ->and($lines->pluck('actor_user_id')->unique()->all())->toBe([$this->admin->id]);

    // Something done while viewing reads as the administrator's act, for the account viewed.
    $this->postJson("/api/v1/view-as/{$this->martha->id}")->assertOk();
    $this->postJson('/api/v1/auth/sign-out')->assertNoContent();
    $out = DB::table('audit_events')->where('action', 'identity.signed_out')->orderByDesc('seq')->first();
    expect($out->actor_user_id)->toBe($this->admin->id)
        ->and($out->acting_for_user_id)->toBe($this->martha->id)
        ->and($out->actor_label)->toBe('Grace Phiri, viewing as Martha Kalua');
});

it('ends on its own when the time is up', function (): void {
    $this->postJson("/api/v1/view-as/{$this->martha->id}")->assertOk();
    $this->travel(61)->minutes();

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $this->admin->id)->assertJsonPath('view_as.by', null);
    expect(DB::table('audit_events')->where('action', 'identity.view_as_ended')->value('meta'))->toContain('ended');
});

it('is not there when it is turned off', function (): void {
    config(['acadly.development.view_as' => false]);

    $this->getJson('/api/v1/view-as/people')->assertNotFound();
    $this->postJson("/api/v1/view-as/{$this->martha->id}")->assertNotFound();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('view_as.available', false);
});
