<?php

declare(strict_types=1);

namespace Database\Seeders;

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Actor;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Audit\Watch;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/**
 * Makes the development administrator from DEV_ADMIN_EMAIL, DEV_ADMIN_NAME and
 * DEV_ADMIN_PASSWORD. It runs only in the local and testing environments and
 * can run again: the account is brought back to active with the configured
 * password, and the administrator role at the whole school is given once.
 */
final class DevelopmentAdministratorSeeder extends Seeder
{
    public function run(Clock $clock, Audit $audit): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The development administrator is made only in the local and testing environments. Use acadly:administrator.');
        }

        $email = mb_strtolower(trim(self::text(config('acadly.development.admin_email'))));
        $name = trim(self::text(config('acadly.development.admin_name')));
        $password = self::text(config('acadly.development.admin_password'));

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', Password::min(12)],
            ],
            [
                'email.required' => 'Set DEV_ADMIN_EMAIL in .env.',
                'password.required' => 'Set DEV_ADMIN_PASSWORD in .env.',
            ],
        );

        if ($validator->fails()) {
            throw new RuntimeException(implode(' ', $validator->errors()->all()));
        }

        if (! Role::query()->where('key', Role::ADMINISTRATOR)->exists()) {
            Artisan::call('acadly:sync');
        }

        $role = Role::query()->where('key', Role::ADMINISTRATOR)->firstOrFail();

        DB::transaction(function () use ($email, $name, $password, $role, $clock, $audit): void {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill(['name' => $name, 'password' => $password, 'status' => AccountStatus::Active])->save();

            $held = RoleAssignment::query()->activeAt($clock->now())
                ->where('user_id', $user->id)->where('role_id', $role->id)
                ->where('scope_type', ScopeType::Institution)->whereNull('scope_id')
                ->exists();

            if ($held) {
                return;
            }

            $assignment = RoleAssignment::query()->create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'scope_type' => ScopeType::Institution,
                'scope_id' => null,
                'starts_at' => $clock->now(),
                'reason' => 'Development administrator.',
            ]);

            $audit->record(KernelAudit::FirstAdministrator, subject: $assignment, changes: Watch::created($assignment)->diff(),
                reason: 'Development administrator.', actor: Actor::system('Development seed'), meta: ['to' => $user->id]);
        });

        $this->command?->getOutput()->writeln("  The development administrator {$email} is ready.");
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
