<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\Totp;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoCampusSchool;

/*
 * Your account (D-089): who you are, the notices that reach you, your
 * password, two-step sign-in, and your lessons as a calendar feed.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->withHeader('Origin', 'http://localhost:5173');
    $this->artisan('acadly:sync')->assertSuccessful();
    app()->forgetScopedInstances();
    $this->school = TwoCampusSchool::make();

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->teacher = User::factory()->create(['name' => 'Tadala Mvula', 'email' => 'tadala@example.test', 'password' => 'Long-enough-password-1']);
    RoleAssignment::query()->create(['user_id' => $this->teacher->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    DB::table('academic_teaching')->insert(['id' => Ids::next(), 'class_id' => $this->school->id('class_primary_a'), 'subject_id' => null, 'user_id' => $this->teacher->id, 'given_at' => now()->subDay()]);
    Auth::forgetGuards();
});

it('shows who you are, your roles, what you teach and the notices that reach you', function (): void {
    $account = $this->actingAs($this->teacher, 'web')->getJson('/api/v1/me/account')->assertOk()->json();

    expect($account['user'])->toMatchArray(['name' => 'Tadala Mvula', 'email' => 'tadala@example.test'])
        ->and($account['roles'])->not->toBe([])
        ->and($account['security'])->toMatchArray(['two_step' => false])
        ->and($account['notices'])->not->toBe([])
        ->and($this->actingAs($this->teacher, 'web')->getJson('/api/v1/me/teaching')->assertOk()->json('class_teacher'))->toBe([DB::table('academic_classes')->where('id', $this->school->id('class_primary_a'))->value('name')]);

    $notice = collect($account['notices'])->first(fn (array $n): bool => ! $n['locked'] && collect($n['channels'])->contains('channel', 'in_app'));
    $after = $this->actingAs($this->teacher, 'web')->putJson('/api/v1/me/account/notices', ['topic' => $notice['key'], 'channel' => 'in_app', 'on' => false])->assertOk()->json('notices');

    expect(collect(collect($after)->firstWhere('key', $notice['key'])['channels'])->firstWhere('channel', 'in_app')['on'])->toBeFalse();
});

it('changes the password only with the current one', function (): void {
    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/account/password', ['current_password' => 'wrong', 'password' => 'A-new-long-password-2', 'password_confirmation' => 'A-new-long-password-2'])
        ->assertUnprocessable()->assertJsonValidationErrors(['current_password']);
    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/account/password', ['current_password' => 'Long-enough-password-1', 'password' => 'A-new-long-password-2', 'password_confirmation' => 'A-new-long-password-2'])
        ->assertOk();

    expect($this->teacher->refresh()->password_changed_at)->not->toBeNull()
        ->and(DB::table('audit_events')->where('action', 'identity.password_changed')->count())->toBe(1);
});

it('asks for a code after the password once two-step sign-in is on, and accepts a recovery code once', function (): void {
    $begin = $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/account/two-step')->assertOk()->json();
    $secret = str_replace(' ', '', $begin['key']);

    expect($begin['address'])->toStartWith('otpauth://totp/');

    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/account/two-step/confirm', ['code' => '000000'])->assertUnprocessable();
    $codes = $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/account/two-step/confirm', ['code' => Totp::code($secret, intdiv(time(), 30))])
        ->assertOk()->json('recovery_codes');
    expect($codes)->toHaveCount(10);

    // Signing in: the password is right, but no session until the code.
    Auth::forgetGuards();
    $this->flushSession();
    $this->postJson('/api/v1/auth/sign-in', ['email' => 'tadala@example.test', 'password' => 'Long-enough-password-1'])->assertStatus(202)->assertJsonPath('two_step', true);
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/two-step', ['code' => '123456'])->assertUnprocessable();
    $this->postJson('/api/v1/auth/two-step', ['code' => $codes[0]])->assertOk()->assertJsonPath('user.email', 'tadala@example.test');

    // A recovery code works once.
    Auth::forgetGuards();
    $this->flushSession();
    $this->postJson('/api/v1/auth/sign-in', ['email' => 'tadala@example.test', 'password' => 'Long-enough-password-1'])->assertStatus(202);
    $this->postJson('/api/v1/auth/two-step', ['code' => $codes[0]])->assertUnprocessable();

    // The office turns it off for someone who lost the phone.
    Auth::forgetGuards();
    $this->flushSession();
    $this->actingAs($this->admin, 'web')->deleteJson("/api/v1/people/{$this->teacher->id}/two-step")->assertOk();
    expect($this->teacher->refresh()->two_step_on_at)->toBeNull();
});

it('gives a private calendar address that stops working when turned off', function (): void {
    $url = $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/calendar')->assertOk()->json('url');
    $path = '/api/v1/calendar/'.basename((string) parse_url($url, PHP_URL_PATH));

    $this->get($path)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    $this->actingAs($this->teacher, 'web')->deleteJson('/api/v1/me/calendar')->assertOk()->assertJsonPath('on', false);
    $this->get($path)->assertNotFound();
});
