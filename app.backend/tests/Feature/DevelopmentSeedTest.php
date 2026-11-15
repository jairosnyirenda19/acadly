<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\User;
use Database\Seeders\DevelopmentAdministratorSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    config([
        'acadly.development.admin_email' => 'Seed.Admin@example.test',
        'acadly.development.admin_name' => 'Seed Administrator',
        'acadly.development.admin_password' => 'a-long-seed-passphrase',
    ]);
});

it('makes the development administrator once, however often it runs', function (): void {
    $this->seed(DevelopmentAdministratorSeeder::class);
    $this->seed(DevelopmentAdministratorSeeder::class);

    $user = User::query()->where('email', 'seed.admin@example.test')->sole();

    expect($user->status)->toBe(AccountStatus::Active)
        ->and(Hash::check('a-long-seed-passphrase', $user->password))->toBeTrue()
        ->and(RoleAssignment::query()->where('user_id', $user->id)
            ->where('role_id', Role::query()->where('key', Role::ADMINISTRATOR)->value('id'))->count())->toBe(1);
});

it('refuses without a password', function (): void {
    config(['acadly.development.admin_password' => null]);

    $this->seed(DevelopmentAdministratorSeeder::class);
})->throws(RuntimeException::class, 'Set DEV_ADMIN_PASSWORD in .env.');
