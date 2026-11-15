<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives an account a role at a scope.
 *
 * Guard rails (docs/architecture/03-deployment-campus-access.md §5.3):
 *  - the assigner must hold "Give and end roles" at that scope;
 *  - the assigner must hold, at that scope, every permission in the role:
 *    nobody can give what they do not have;
 *  - the account must be active, and an end date must be in the future.
 */
final class AssignRole
{
    public function __construct(
        private readonly Access $access,
        private readonly PermissionCatalogue $catalogue,
        private readonly Clock $clock,
    ) {}

    public function handle(
        User $by,
        User $to,
        Role $role,
        ScopeRef $at,
        ?CarbonImmutable $endsAt = null,
        ?string $reason = null,
    ): RoleAssignment {
        $this->access->check($by, AccessPermission::AssignRoles, $at);

        foreach ($role->permissionKeys() as $key) {
            if (! $this->access->allows($by, $this->catalogue->get($key), $at)) {
                throw new AuthorizationException('A role cannot include a permission that the assigner does not hold at this scope.');
            }
        }

        $now = $this->clock->now();

        if (! $to->isActive()) {
            throw ValidationException::withMessages(['user' => ['The account is disabled.']]);
        }

        if ($endsAt !== null && $endsAt <= $now) {
            throw ValidationException::withMessages(['ends_at' => ['The end date must be in the future.']]);
        }

        $assignment = DB::transaction(fn (): RoleAssignment => RoleAssignment::query()->create([
            'user_id' => $to->id,
            'role_id' => $role->id,
            'scope_type' => $at->type,
            'scope_id' => $at->id,
            'starts_at' => $now,
            'ends_at' => $endsAt,
            'granted_by' => $by->id,
            'reason' => $reason,
        ]));

        $this->access->forget($to);

        return $assignment;
    }
}
