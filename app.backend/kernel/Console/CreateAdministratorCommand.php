<?php

declare(strict_types=1);

namespace Acadly\Kernel\Console;

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the first administrator of a new deployment. It runs only while no
 * administrator exists; after that, roles are given in the application.
 */
final class CreateAdministratorCommand extends Command
{
    protected $signature = 'acadly:administrator {email : The administrator\'s email address} {--name= : The administrator\'s full name}';

    protected $description = 'Create the first administrator of this deployment';

    public function handle(Clock $clock): int
    {
        $role = Role::query()->where('key', Role::ADMINISTRATOR)->first();

        if ($role === null) {
            $this->components->error('The administrator role does not exist. Run acadly:sync first.');

            return self::FAILURE;
        }

        if (RoleAssignment::query()->activeAt($clock->now())->where('role_id', $role->id)->exists()) {
            $this->components->error('An administrator already exists. Give further roles in the application.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim(self::text($this->argument('email'))));
        $name = trim(self::text($this->option('name')));
        $name = $name !== '' ? $name : trim(self::text($this->ask('Full name')));
        $password = self::text($this->secret('Password (at least 12 characters)'));
        $confirmation = self::text($this->secret('Confirm the password'));

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'email' => ['required', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $name, $password, $role, $clock): void {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill(['name' => $name, 'password' => $password, 'status' => AccountStatus::Active])->save();

            RoleAssignment::query()->create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'scope_type' => ScopeType::Institution,
                'scope_id' => null,
                'starts_at' => $clock->now(),
                'reason' => 'First administrator of the deployment.',
            ]);
        });

        $this->components->info("The administrator account {$email} is ready.");

        return self::SUCCESS;
    }

    /** Console input as text; anything that is not a string counts as empty. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
