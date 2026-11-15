<?php

declare(strict_types=1);

use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Actions\AssignRole;
use Acadly\Kernel\Access\Actions\EndRoleAssignment;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Audit\Actor;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\Chain;
use Acadly\Kernel\Audit\ChainVerifier;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Audit\Outcome;
use Acadly\Kernel\Audit\Seals;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    $this->withHeader('Origin', 'http://localhost:5173');
    Storage::fake();
});

function administrator(): User
{
    $user = User::factory()->create();
    $role = Role::query()->where('key', Role::ADMINISTRATOR)->firstOrFail();

    RoleAssignment::query()->create([
        'user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => 'institution',
        'scope_id' => null, 'starts_at' => now()->subMinute(),
    ]);

    return $user;
}

/** @return object{action: string, outcome: string, changes: string, payload_id: ?string, reason: ?string, actor_kind: string, actor_roles: string, subject_id: ?string} */
function lastLine(): object
{
    /** @var object{action: string, outcome: string, changes: string, payload_id: ?string, reason: ?string, actor_kind: string, actor_roles: string, subject_id: ?string} */
    return DB::table('audit_events')->orderByDesc('seq')->first();
}

/**
 * A small chain built in memory, for the verifier.
 *
 * @return list<array<string, mixed>>
 */
function memoryChain(int $count): array
{
    $lines = [];
    $previous = Chain::GENESIS;

    for ($seq = 1; $seq <= $count; $seq++) {
        $line = [
            'id' => Ids::next(), 'seq' => $seq, 'occurred_at' => '2026-11-16T09:00:00.000000Z',
            'module' => 'access', 'action' => 'access.role_assigned', 'outcome' => 'done', 'actor_kind' => 'system',
            'actor_roles' => '[]', 'channel' => 'job', 'changes' => '{"ends_at":{"old":null,"new":"x"}}', 'meta' => '{}',
        ];
        $line['prev_hash'] = $previous;
        $line['hash'] = $previous = Chain::hash($previous, $line);
        $lines[] = $line;
    }

    return $lines;
}

/** @param  list<array<string, mixed>>  $lines */
function verifyMemory(array $lines): array
{
    $verifier = new ChainVerifier;

    foreach ($lines as $line) {
        $verifier->add($line);
    }

    return $verifier->finish([]);
}

it('chains every line to the one before and verifies', function (): void {
    $audit = app(Audit::class);

    $audit->record(KernelAudit::Sealed, actor: Actor::system());
    $audit->record(KernelAudit::Sealed, actor: Actor::system());

    $lines = DB::table('audit_events')->orderByDesc('seq')->limit(2)->get()->reverse()->values();

    expect((int) $lines[1]->seq)->toBe((int) $lines[0]->seq + 1)
        ->and($lines[1]->prev_hash)->toBe($lines[0]->hash)
        ->and(app(Seals::class)->verify()['problems'])->toBe([]);
});

it('detects a changed line, a removed line and a broken link', function (): void {
    expect(verifyMemory(memoryChain(4)))->toBe([]);

    $changed = memoryChain(4);
    $changed[1]['outcome'] = 'refused';
    expect(verifyMemory($changed))->toContain('Line 2 has been changed: its hash does not match its content.');

    $removed = memoryChain(4);
    unset($removed[2]);
    expect(implode(' ', verifyMemory(array_values($removed))))->toContain('Line 3 is missing');
});

it('detects a seal that names fewer lines than an earlier one', function (): void {
    $lines = memoryChain(3);
    $verifier = new ChainVerifier([2 => true, 3 => true]);

    foreach ($lines as $line) {
        $verifier->add($line);
    }

    $problems = $verifier->finish([
        ['last_seq' => 3, 'head_hash' => $lines[2]['hash'], 'line_count' => 3, 'valid_signature' => true],
        ['last_seq' => 2, 'head_hash' => $lines[1]['hash'], 'line_count' => 2, 'valid_signature' => true],
    ]);

    expect($problems)->toContain('The seal at line 2 names fewer lines than an earlier seal.');
});

it('refuses to change or remove a line', function (): void {
    app(Audit::class)->record(KernelAudit::Sealed, actor: Actor::system());

    DB::table('audit_events')->update(['outcome' => 'refused']);
})->throws(QueryException::class);

it('writes a failed sign-in with the address encrypted, not in the line', function (): void {
    $user = User::factory()->create(['email' => 'teacher@example.org']);

    $this->postJson('/api/v1/auth/sign-in', ['email' => 'teacher@example.org', 'password' => 'wrong-password-123'])
        ->assertUnprocessable();

    $line = lastLine();
    $raw = json_encode($line, JSON_THROW_ON_ERROR);

    expect($line->action)->toBe('identity.sign_in_failed')
        ->and($line->outcome)->toBe('failed')
        ->and($line->actor_kind)->toBe('anonymous')
        ->and($line->subject_id)->toBe($user->id)
        ->and($raw)->not->toContain('teacher@example.org');

    $payload = DB::table('audit_payloads')->where('id', $line->payload_id)->first();
    $plain = Crypt::decryptString((string) $payload?->ciphertext);

    expect($plain)->toContain('teacher@example.org')
        ->and(hash('sha256', $plain))->toBe($payload?->payload_hash);
});

it('writes a successful sign-in with the roles held', function (): void {
    $user = administrator();
    $user->forceFill(['password' => 'correct-horse-battery'])->save();

    $this->postJson('/api/v1/auth/sign-in', ['email' => $user->email, 'password' => 'correct-horse-battery'])->assertOk();

    $line = lastLine();

    expect($line->action)->toBe('identity.signed_in')
        ->and(json_decode($line->actor_roles, true))->toBe([Role::ADMINISTRATOR]);
});

it('masks a password change and keeps only the fact of it', function (): void {
    $user = User::factory()->create();
    $watch = app(Audit::class)->watch($user);
    $user->forceFill(['password' => 'a-new-password-1234', 'status' => 'disabled'])->save();

    app(Audit::class)->record(KernelAudit::PasswordReset, subject: $user, changes: $watch->diff(), actor: Actor::system());

    expect(json_decode(lastLine()->changes, true))->toEqual([
        'password' => ['masked' => true],
        'status' => ['old' => 'active', 'new' => 'disabled'],
    ]);
});

it('writes a role given and a role ended, with the reason', function (): void {
    $admin = administrator();
    $to = User::factory()->create();
    $role = Role::query()->create(['key' => 'office_'.substr(Ids::next(), -8), 'name' => 'Office']);
    $role->grant([AccessPermission::AssignRoles]);

    $assignment = app(AssignRole::class)->handle($admin, $to, $role, ScopeRef::institution(), reason: 'Joined the office.');
    $given = lastLine();

    app(EndRoleAssignment::class)->handle($admin, $assignment, 'Left the office.');
    $ended = lastLine();

    expect($given->action)->toBe('access.role_assigned')
        ->and($given->reason)->toBe('Joined the office.')
        ->and($ended->action)->toBe('access.role_ended')
        ->and($ended->reason)->toBe('Left the office.')
        ->and(array_keys(json_decode($ended->changes, true)))->toBe(['ends_at']);
});

it('writes a refused attempt', function (): void {
    $nobody = User::factory()->create();

    try {
        app(AssignRole::class)->handle($nobody, $nobody, Role::query()->firstOrFail(), ScopeRef::institution());
    } catch (AuthorizationException) {
        // expected
    }

    $line = lastLine();

    expect($line->action)->toBe('access.refused')
        ->and($line->outcome)->toBe(Outcome::Refused->value);
});

it('requires a reason where the catalogue says so', function (): void {
    app(Audit::class)->record(KernelAudit::RoleEnded, actor: Actor::system());
})->throws(LogicException::class, 'requires a reason');

it('refuses a reason that is blank when ending a role', function (): void {
    $admin = administrator();
    $assignment = RoleAssignment::query()->where('user_id', $admin->id)->firstOrFail();

    app(EndRoleAssignment::class)->handle($admin, $assignment, '  ');
})->throws(ValidationException::class);

it('seals the head, keeps a copy outside the database, and detects a changed copy', function (): void {
    app(Audit::class)->record(KernelAudit::SignedOut, actor: Actor::system());

    $this->artisan('audit:seal')->assertSuccessful();

    $seal = DB::table('audit_seals')->orderByDesc('last_seq')->first();
    $file = Seals::FOLDER.'/'.$seal?->id.'.json';

    Storage::assertExists($file);
    $this->artisan('audit:verify')->assertSuccessful();

    Storage::put($file, '{"last_seq":1}');

    expect(app(Seals::class)->verify()['problems'])
        ->toContain("The seal at line {$seal?->last_seq} differs from its copy outside the database.");
});
