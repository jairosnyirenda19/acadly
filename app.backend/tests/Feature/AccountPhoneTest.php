<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\Jobs\SendSignInCodeText;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fixtures\TwoCampusSchool;

/*
 * One account for a member of staff who is also a parent (D-087): the phone
 * number added to the staff account and confirmed by a code, the parent
 * account on that number closed and its children moved, and a sign-in by
 * code that reaches the parent portal only.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->withHeader('Origin', 'http://localhost:5173');
    $this->artisan('acadly:sync')->assertSuccessful();
    app()->forgetScopedInstances();
    RateLimiter::clear('sign-in-code:+265884771900');
    Queue::fake();
    $this->school = TwoCampusSchool::make();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subMinute()]);

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->staffWorkIn('division', $this->school->id('primary'));

    $this->alinafe = $this->actingAs($this->admin)->postJson('/api/v1/learners', [
        'first_name' => 'Alinafe', 'other_names' => '', 'surname' => 'Mvula', 'sex' => 'female', 'date_of_birth' => '2018-03-04',
        'class_id' => $this->school->id('class_primary_a'), 'admitted_on' => '2026-09-08', 'admission_number' => '', 'contacts' => [
            ['name' => 'Tadala Mvula', 'relationship' => 'mother', 'phone' => '0884 771 900', 'email' => '', 'main' => true],
            ['name' => 'Peter Mvula', 'relationship' => 'father', 'phone' => '0888 410 299', 'email' => '', 'main' => false],
        ],
    ])->assertCreated()->json('id');

    // A teacher at the school, who is also Alinafe's mother.
    $this->teacher = User::factory()->create(['name' => 'Tadala Mvula', 'email' => 'tadala@example.test']);
    RoleAssignment::query()->create(['user_id' => $this->teacher->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    Auth::forgetGuards();
});

/** The newest code sent by SMS to this number. */
function newestCode(string $phone): string
{
    $code = '';
    Queue::assertPushed(SendSignInCodeText::class, function (SendSignInCodeText $job) use (&$code, $phone): bool {
        if ($job->phone === $phone && preg_match('/\b(\d{6})\b/', $job->text, $m) === 1) {
            $code = $m[1];
        }

        return true;
    });

    return $code;
}

it('reaches the parent portal only, after a sign-in by code', function (): void {
    $this->postJson('/api/v1/auth/phone/code', ['phone' => '0884 771 900'])->assertOk();
    $this->postJson('/api/v1/auth/phone/sign-in', ['phone' => '0884 771 900', 'code' => newestCode('+265884771900')])->assertOk()
        ->assertJsonPath('signed_in_by', 'code')->assertJsonPath('grants', []);

    $this->getJson('/api/v1/portal/family')->assertOk();
    $this->getJson('/api/v1/learners')->assertForbidden()->assertJsonPath('code', 'password_required');
    $this->getJson('/api/v1/registers/my-day')->assertForbidden()->assertJsonPath('code', 'password_required');
});

it('adds a phone to a staff account with a code, and the portal then opens that account, never the console', function (): void {
    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/phone/code', ['phone' => '0884 771 900'])->assertOk();
    $code = newestCode('+265884771900');

    // A code to add the number does not sign anyone in.
    $this->postJson('/api/v1/auth/phone/sign-in', ['phone' => '0884 771 900', 'code' => $code])->assertUnprocessable();

    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/phone', ['phone' => '0884 771 900', 'code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/phone', ['phone' => '0884 771 900', 'code' => $code])->assertOk()
        ->assertJsonPath('user.phone', '+265884771900')->assertJsonPath('signed_in_by', 'password');

    expect(DB::table('audit_events')->where('action', 'identity.account_phone_added')->count())->toBe(1);

    // Signing in by code now opens the teacher's own account, for the portal only.
    Auth::forgetGuards();
    $this->flushSession();
    $this->postJson('/api/v1/auth/phone/code', ['phone' => '0884 771 900'])->assertOk();
    $this->postJson('/api/v1/auth/phone/sign-in', ['phone' => '0884 771 900', 'code' => newestCode('+265884771900')])->assertOk()
        ->assertJsonPath('user.id', $this->teacher->id)->assertJsonPath('grants', []);
    $this->getJson('/api/v1/portal/family')->assertOk()->assertJsonPath('to_confirm.0.id', $this->alinafe);
    $this->getJson('/api/v1/learners')->assertForbidden()->assertJsonPath('code', 'password_required');
});

it('closes the parent account already on the number and moves its children to the staff account', function (): void {
    $this->postJson('/api/v1/auth/phone/code', ['phone' => '0884 771 900'])->assertOk();
    $this->postJson('/api/v1/auth/phone/sign-in', ['phone' => '0884 771 900', 'code' => newestCode('+265884771900')])->assertOk();
    $this->postJson('/api/v1/portal/family/confirm', ['mine' => [$this->alinafe], 'not_mine' => []])->assertOk();
    $parent = User::query()->where('phone', '+265884771900')->firstOrFail();

    Auth::forgetGuards();
    $this->flushSession();
    RateLimiter::clear('sign-in-code:+265884771900');
    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/phone/code', ['phone' => '0884 771 900'])->assertOk();
    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/phone', ['phone' => '0884 771 900', 'code' => newestCode('+265884771900')])->assertOk();

    expect($parent->refresh()->phone)->toBeNull()
        ->and($parent->status->value)->toBe('disabled')
        ->and($this->actingAs($this->teacher, 'web')->getJson('/api/v1/portal/family')->json('children.0.id'))->toBe($this->alinafe);
    $this->actingAs($this->teacher, 'web')->getJson("/api/v1/portal/children/{$this->alinafe}/attendance")->assertOk();
});

it('refuses a number the school does not keep, and a number on another staff account', function (): void {
    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/phone/code', ['phone' => '0999 000 111'])->assertUnprocessable()->assertJsonValidationErrors(['phone']);

    $this->admin->forceFill(['phone' => '+265884771900'])->save();
    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/phone/code', ['phone' => '0884 771 900'])->assertUnprocessable()->assertJsonValidationErrors(['phone']);
});

it('gives the children back to an account of the number when the staff member removes it', function (): void {
    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/phone/code', ['phone' => '0884 771 900'])->assertOk();
    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/phone', ['phone' => '0884 771 900', 'code' => newestCode('+265884771900')])->assertOk();
    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/portal/family/confirm', ['mine' => [$this->alinafe], 'not_mine' => []])->assertOk();

    $this->actingAs($this->teacher, 'web')->deleteJson('/api/v1/me/phone')->assertOk()->assertJsonPath('user.phone', null);

    $own = User::query()->where('phone', '+265884771900')->firstOrFail();

    expect($own->id)->not->toBe($this->teacher->id)
        ->and($own->email)->toBeNull()
        ->and($this->actingAs($this->teacher, 'web')->getJson('/api/v1/portal/family')->json('children'))->toBe([]);

    // A new session for the other account, as on another phone.
    Auth::forgetGuards();
    $this->flushSession();
    expect($this->actingAs($own, 'web')->getJson('/api/v1/portal/family')->json('children.0.id'))->toBe($this->alinafe);
});

it('sends the code to add a number in Chichewa when asked', function (): void {
    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/phone/code', ['phone' => '0884 771 900', 'language' => 'ny'])->assertOk();

    Queue::assertPushed(SendSignInCodeText::class, fn (SendSignInCodeText $job): bool => str_contains($job->text, 'nambala yowonjezera nambalayi ku akaunti yanu'));
});

it('keeps a phone the remember cookie brings back to the parent portal only', function (): void {
    $this->postJson('/api/v1/auth/phone/code', ['phone' => '0884 771 900'])->assertOk();
    $signedIn = $this->postJson('/api/v1/auth/phone/sign-in', ['phone' => '0884 771 900', 'code' => newestCode('+265884771900')])->assertOk();
    $recaller = Auth::guard('web')->getRecallerName();
    $cookie = collect($signedIn->headers->getCookies())->first(fn ($c): bool => $c->getName() === $recaller);

    expect($cookie)->not->toBeNull();

    // Days later: the session is gone, only the remember cookie is left.
    Auth::forgetGuards();
    $this->flushSession();
    $this->withCredentials()->withUnencryptedCookie($recaller, (string) $cookie->getValue());

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('signed_in_by', 'code')->assertJsonPath('grants', []);
    $this->getJson('/api/v1/portal/family')->assertOk();
    $this->getJson('/api/v1/learners')->assertForbidden()->assertJsonPath('code', 'password_required');
});

it('keeps each wrong try at a code to add a phone, and refuses the right code after five', function (): void {
    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/phone/code', ['phone' => '0884 771 900'])->assertOk();
    $code = newestCode('+265884771900');
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $try) {
        $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/phone', ['phone' => '0884 771 900', 'code' => $wrong])->assertUnprocessable();
    }

    expect(DB::table('sign_in_codes')->where('purpose', 'like', 'link:%')->value('attempts'))->toBe(5);
    $this->actingAs($this->teacher, 'web')->postJson('/api/v1/me/phone', ['phone' => '0884 771 900', 'code' => $code])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    expect($this->teacher->fresh()?->phone)->toBeNull();
});
