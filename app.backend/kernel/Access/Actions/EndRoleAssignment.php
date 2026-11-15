<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ends a role assignment now. The row is kept, with who ended it and why.
 *
 * The last institution-wide administrator cannot be ended: the school would
 * have nobody left who can give roles.
 */
final class EndRoleAssignment
{
    public function __construct(
        private readonly Access $access,
        private readonly Clock $clock,
    ) {}

    public function handle(User $by, RoleAssignment $assignment, string $reason): void
    {
        $this->access->check($by, AccessPermission::AssignRoles, $assignment->scope());

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        $now = $this->clock->now();

        DB::transaction(function () use ($by, $assignment, $reason, $now): void {
            $locked = RoleAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($locked->ends_at !== null && $locked->ends_at <= $now) {
                throw ValidationException::withMessages(['assignment' => ['The assignment has already ended.']]);
            }

            if ($this->isLastAdministrator($locked)) {
                throw ValidationException::withMessages(['assignment' => ['The last administrator cannot be removed.']]);
            }

            $locked->forceFill([
                'ends_at' => $now,
                'ended_by' => $by->id,
                'end_reason' => $reason,
            ])->save();
        });

        $user = User::query()->find($assignment->user_id);

        if ($user !== null) {
            $this->access->forget($user);
        }
    }

    private function isLastAdministrator(RoleAssignment $assignment): bool
    {
        $administrator = Role::query()->where('key', Role::ADMINISTRATOR)->first();

        if ($administrator === null || $assignment->role_id !== $administrator->id || $assignment->scope_id !== null) {
            return false;
        }

        return RoleAssignment::query()
            ->activeAt($this->clock->now())
            ->where('role_id', $administrator->id)
            ->whereNull('scope_id')
            ->whereKeyNot($assignment->id)
            ->doesntExist();
    }
}
