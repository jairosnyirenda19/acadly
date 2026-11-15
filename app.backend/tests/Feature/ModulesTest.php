<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Http\Problem;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\Licence\Licences;
use Acadly\Kernel\Module\Licence\LicenceStatus;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Module\ModuleState;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    $this->withHeader('Origin', 'http://localhost:5173');
    Queue::fake();
    app()->forgetScopedInstances();
});

/** @return array{0: string, 1: string} secret key, public key (base64) */
function licenceKeys(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [sodium_crypto_sign_secretkey($pair), base64_encode(sodium_crypto_sign_publickey($pair))];
}

/** @param  array<string, mixed>  $body */
function signLicence(array $body, string $secret): string
{
    $signature = sodium_crypto_sign_detached(Licences::canonical($body), $secret);

    return json_encode(['licence' => $body, 'signature' => base64_encode($signature)], JSON_THROW_ON_ERROR);
}

function installLicence(array $modules, string $expires = '2099-12-31T00:00:00Z'): void
{
    [$secret, $public] = licenceKeys();
    config(['acadly.licence_public_key' => $public]);

    DB::table('system_licences')->insert([
        'id' => Ids::next(),
        'serial' => 'TEST-1',
        'document' => signLicence(['serial' => 'TEST-1', 'licensee' => 'Sunrise Academy', 'modules' => $modules, 'expires_at' => $expires], $secret),
        'installed_at' => now(),
    ]);

    app()->forgetScopedInstances();
}

function schoolAdministrator(): User
{
    $user = User::factory()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', Role::ADMINISTRATOR)->firstOrFail()->id,
        'scope_type' => 'institution', 'scope_id' => null, 'starts_at' => now()->subMinute(),
    ]);

    return $user;
}

it('licenses every module on a development machine with no licence key', function (): void {
    expect(app(Licences::class)->current()->status)->toBe(LicenceStatus::Development)
        ->and(app(ModuleActivation::class)->state('attendance'))->toBe(ModuleState::SwitchedOff)
        ->and(app(ModuleActivation::class)->state('institution'))->toBe(ModuleState::Active);
});

it('accepts a signed licence and refuses a changed one', function (): void {
    [$secret, $public] = licenceKeys();
    $document = signLicence(['serial' => 'A-1', 'modules' => ['attendance'], 'expires_at' => '2099-01-01T00:00:00Z'], $secret);
    $licences = app(Licences::class);

    expect($licences->read($document, base64_decode($public))->includes('attendance'))->toBeTrue();

    $enlarged = str_replace('["attendance"]', '["attendance","library"]', $document);
    $licences->read($enlarged, base64_decode($public));
})->throws(InvalidArgumentException::class, 'does not verify');

it('keeps a module off when the licence does not include it', function (): void {
    installLicence([]);

    expect(app(ModuleActivation::class)->state('attendance'))->toBe(ModuleState::NotLicensed);

    $this->actingAs(schoolAdministrator())
        ->putJson('/api/v1/modules/attendance', ['enabled' => true, 'reason' => 'Start of term.'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.module.0', 'The licence does not include this module.');
});

it('switches a module on with a reason, audits it and tells other modules', function (): void {
    $admin = schoolAdministrator();

    $this->actingAs($admin)
        ->putJson('/api/v1/modules/attendance', ['enabled' => true, 'reason' => 'Start of term.'])
        ->assertOk();

    app()->forgetScopedInstances();

    $line = DB::table('audit_events')->orderByDesc('seq')->first();

    expect(app(ModuleActivation::class)->isActive('attendance'))->toBeTrue()
        ->and($line?->action)->toBe('modules.switched')
        ->and($line?->reason)->toBe('Start of term.')
        ->and(DB::table('outbox_events')->where('name', 'modules.switched')->exists())->toBeTrue();

    $this->actingAs($admin)->getJson('/api/v1/me/context')
        ->assertOk()
        ->assertJsonPath('modules', ['institution', 'attendance'])
        ->assertJsonPath('school.name', config('acadly.school_name'))
        ->assertJsonPath('licence.read_only', false);
});

it('refuses to switch without a reason, a core function, or a module not in the build', function (): void {
    $admin = schoolAdministrator();

    $this->actingAs($admin)->putJson('/api/v1/modules/attendance', ['enabled' => true, 'reason' => ''])->assertUnprocessable();
    $this->actingAs($admin)->putJson('/api/v1/modules/institution', ['enabled' => false, 'reason' => 'Test.'])
        ->assertUnprocessable()->assertJsonPath('errors.module.0', 'Core functions are always on.');
    $this->actingAs($admin)->putJson('/api/v1/modules/library', ['enabled' => true, 'reason' => 'Test.'])
        ->assertUnprocessable()->assertJsonPath('errors.module.0', 'The module is not part of this build.');
});

it('refuses switching to someone without the permission', function (): void {
    $this->actingAs(User::factory()->create())
        ->putJson('/api/v1/modules/attendance', ['enabled' => true, 'reason' => 'Test.'])
        ->assertForbidden()
        ->assertJsonPath('code', 'access_refused');
});

it('refuses a permission of a module that is off, and says why', function (): void {
    $admin = schoolAdministrator();

    expect(app(Access::class)->allows($admin, AttendancePermission::Capture))->toBeFalse();

    try {
        app(Access::class)->check($admin, AttendancePermission::Capture);
    } catch (Problem $problem) {
        expect($problem->problemCode)->toBe('module_inactive');
    }

    expect(DB::table('audit_events')->orderByDesc('seq')->value('refusal'))->toBe('module:attendance');
});

it('guards a module route, and allows only reading while the licence is expired', function (): void {
    Route::middleware(['api', 'module:attendance'])->match(['GET', 'POST'], '/api/v1/probe-module', fn () => response()->json(['ok' => true]));

    $this->getJson('/api/v1/probe-module')->assertForbidden()->assertJsonPath('code', 'module_inactive');

    DB::table('module_switches')->insert([
        'id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => true, 'reason' => 'Test.', 'switched_at' => now(),
    ]);
    installLicence(['attendance'], '2020-01-01T00:00:00Z');

    expect(app(Licences::class)->current()->status)->toBe(LicenceStatus::Expired);

    $this->getJson('/api/v1/probe-module')->assertOk();
    $this->postJson('/api/v1/probe-module')->assertForbidden()->assertJsonPath('code', 'licence_read_only');
});

it('keeps the history of switches', function (): void {
    $admin = schoolAdministrator();

    $this->actingAs($admin)->putJson('/api/v1/modules/attendance', ['enabled' => true, 'reason' => 'On.'])->assertOk();
    app()->forgetScopedInstances();
    $this->actingAs($admin)->putJson('/api/v1/modules/attendance', ['enabled' => false, 'reason' => 'Off.'])->assertOk();
    app()->forgetScopedInstances();

    expect(DB::table('module_switches')->where('module_key', 'attendance')->count())->toBe(2)
        ->and(app(ModuleActivation::class)->isActive('attendance'))->toBeFalse();
});
