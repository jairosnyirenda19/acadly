<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\Contrast;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/* The school's own look (D-096): the name the console shows and two colours, checked for readability. */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->withHeader('Origin', 'http://localhost:5173');
    $this->artisan('acadly:sync')->assertSuccessful();
    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
});

it('keeps the product name and colours until the school chooses its own', function (): void {
    $look = $this->actingAs($this->admin)->getJson('/api/v1/me/context')->assertOk()->json('school.look');

    expect($look)->toBe(['app_name' => null, 'main' => Contrast::MAIN, 'accent' => Contrast::ACCENT, 'accent_last' => true, 'custom' => false]);
});

it('saves a name and readable colours, and shows them on every screen and the sign-in page', function (): void {
    $this->actingAs($this->admin)->putJson('/api/v1/institution/look', ['app_name' => '  Sunrise   Valley Academy ', 'main' => '#123e2a', 'accent' => '#F2C14E', 'accent_last' => true])
        ->assertOk()->assertJson(['app_name' => 'Sunrise Valley Academy', 'main' => '#123E2A', 'accent' => '#F2C14E', 'custom' => true]);

    expect($this->actingAs($this->admin)->getJson('/api/v1/me/context')->json('school.look.app_name'))->toBe('Sunrise Valley Academy')
        ->and($this->getJson('/api/v1/school')->json('look.main'))->toBe('#123E2A');

    // Empty values return to the product's own.
    $this->actingAs($this->admin)->putJson('/api/v1/institution/look', ['app_name' => '', 'main' => '', 'accent' => ''])->assertOk()->assertJson(['app_name' => null, 'custom' => false]);
});

it('refuses a name over 40 letters and colours that cannot be read, offering the nearest that can', function (): void {
    $this->actingAs($this->admin)->putJson('/api/v1/institution/look', ['app_name' => str_repeat('A', 41)])->assertUnprocessable()->assertJsonValidationErrors(['app_name']);
    $this->actingAs($this->admin)->putJson('/api/v1/institution/look', ['main' => 'green'])->assertUnprocessable()->assertJsonValidationErrors(['main']);

    $light = $this->actingAs($this->admin)->putJson('/api/v1/institution/look', ['main' => '#9AD1FF'])->assertUnprocessable()->json('errors.main.0');
    expect($light)->toContain('The nearest that can: #');

    $this->actingAs($this->admin)->putJson('/api/v1/institution/look', ['main' => '#123E2A', 'accent' => '#1F4A35'])->assertUnprocessable()->assertJsonValidationErrors(['accent']);
});

it('lets only those who keep the school details change the look', function (): void {
    $other = User::factory()->create();
    $this->actingAs($other)->putJson('/api/v1/institution/look', ['app_name' => 'X'])->assertForbidden();
});

it('works the contrast out as the WCAG formula does', function (): void {
    expect(Contrast::ratio('#FFFFFF', '#000000'))->toBe(21.0)
        ->and(Contrast::ratio('#FFFFFF', '#0C2340'))->toBeGreaterThan(14.0)
        ->and(Contrast::ratio('#FFFFFF', Contrast::nearestMain('#9AD1FF')))->toBeGreaterThanOrEqual(4.5);
});
