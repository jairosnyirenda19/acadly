<?php

declare(strict_types=1);

use Acadly\Kernel\Identity\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(DatabaseTransactions::class);

/** Requests from the console in development are stateful (session cookie). */
beforeEach(function (): void {
    $this->withHeader('Origin', 'http://localhost:5173');
});

it('signs in with a correct email and password and returns the account', function (): void {
    $user = User::factory()->create(['email' => 'Head@Example.org']);

    $this->postJson('/api/v1/auth/sign-in', ['email' => 'head@example.org', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', 'head@example.org')
        ->assertJsonPath('grants', []);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $user->id);

    expect($user->fresh()?->last_signed_in_at)->not->toBeNull();
});

it('gives one message for a wrong password, an unknown address and a disabled account', function (): void {
    User::factory()->create(['email' => 'teacher@example.org']);
    User::factory()->disabled()->create(['email' => 'former@example.org']);

    $message = 'The email address or password is incorrect.';

    foreach ([
        ['teacher@example.org', 'wrong-password'],
        ['nobody@example.org', 'password'],
        ['former@example.org', 'password'],
    ] as [$email, $password]) {
        $this->postJson('/api/v1/auth/sign-in', ['email' => $email, 'password' => $password])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.email.0', $message);
    }
});

it('locks an address after five failed attempts', function (): void {
    User::factory()->create(['email' => 'teacher@example.org']);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/sign-in', ['email' => 'teacher@example.org', 'password' => 'wrong'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/sign-in', ['email' => 'teacher@example.org', 'password' => 'password'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests');
});

it('refuses /me without a session, with a clean problem document', function (): void {
    $this->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertExactJson([
            'type' => 'about:blank',
            'title' => 'Authentication is required.',
            'status' => 401,
            'code' => 'unauthenticated',
        ]);
});

it('refuses a guest with 401 also when the client does not ask for JSON', function (): void {
    $this->get('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');
});

it('signs out', function (): void {
    $user = User::factory()->create();
    $this->postJson('/api/v1/auth/sign-in', ['email' => $user->email, 'password' => 'password'])->assertOk();

    $this->postJson('/api/v1/auth/sign-out')->assertNoContent();

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/me')->assertStatus(401);
});

it('answers a reset request the same way whether or not the account exists', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'parent@example.org']);

    foreach (['parent@example.org', 'nobody@example.org'] as $email) {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])
            ->assertStatus(202)
            ->assertExactJson([
                'status' => 'accepted',
                'message' => 'If an account exists for this email address, a reset link has been sent.',
            ]);
    }

    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);
});

it('sends no reset link to a disabled account', function (): void {
    Notification::fake();
    User::factory()->disabled()->create(['email' => 'former@example.org']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'former@example.org'])->assertStatus(202);

    Notification::assertNothingSent();
});

it('sets a new password with a valid reset link and refuses a wrong one', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'parent@example.org']);
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'parent@example.org'])->assertStatus(202);

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'parent@example.org', 'token' => 'not-the-token',
        'password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password',
    ])->assertStatus(422)->assertJsonPath('errors.token.0', 'The reset link is not valid or has expired.');

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'parent@example.org', 'token' => $token,
        'password' => 'short', 'password_confirmation' => 'short',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'parent@example.org', 'token' => $token,
        'password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password',
    ])->assertNoContent();

    expect(Hash::check('a-new-long-password', (string) $user->fresh()?->password))->toBeTrue();
});

it('points the reset link at the console and names no technology', function (): void {
    $user = User::factory()->create(['email' => 'parent@example.org']);
    $mail = (new ResetPassword('abc123'))->toMail($user);

    expect($mail->actionUrl)->toBe('http://localhost:5173/reset-password?token=abc123&email=parent%40example.org')
        ->and($mail->subject)->toBe('Password reset')
        ->and(strtolower(implode(' ', $mail->introLines)))->not->toContain('laravel');
});

it('reads and writes time in UTC on the database connection', function (): void {
    expect(DB::selectOne('show timezone')->TimeZone)->toBe('UTC')
        ->and(DB::connection('pgsql_owner')->selectOne('show timezone')->TimeZone)->toBe('UTC');
});
