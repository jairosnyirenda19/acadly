<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removes a role (as eOffice IQ-X does). Refused for the system role, and
 * while anybody holds the role: removing it would change what they may do
 * without saying so. A role held in the past is retired, so the history of
 * who held it stays whole; a role never held is deleted.
 */
final class RemoveRole
{
    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @return 'retired'|'deleted' */
    public function handle(User $by, Role $role): string
    {
        $this->access->check($by, AccessPermission::ManageRoles);

        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => ['The system role holds every permission and cannot be removed.']]);
        }

        $now = $this->clock->now();
        $holding = RoleAssignment::query()->where('role_id', $role->id)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->distinct()->count('user_id');

        if ($holding > 0) {
            throw ValidationException::withMessages(['role' => [
                ($holding === 1 ? '1 person holds' : "{$holding} people hold").' this role. End it for them first: removing it here would change what they may do without saying so.',
            ]]);
        }

        $held = RoleAssignment::query()->where('role_id', $role->id)->exists();

        return DB::transaction(function () use ($by, $role, $held, $now): string {
            if ($held) {
                $role->forceFill(['retired_at' => $now])->save();
                $this->audit->record(KernelAudit::RoleRetired, subject: $role, actor: $this->audit->actorFor($by), meta: ['role' => $role->key]);

                return 'retired';
            }

            $this->audit->record(KernelAudit::RoleDeleted, subject: $role, actor: $this->audit->actorFor($by), meta: ['role' => $role->key, 'name' => $role->name]);
            DB::table('access_role_permissions')->where('role_id', $role->id)->delete();
            $role->delete();

            return 'deleted';
        });
    }
}
