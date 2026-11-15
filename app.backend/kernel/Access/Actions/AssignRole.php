<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Events\RoleAssigned;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeHierarchy;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Audit\Outcome;
use Acadly\Kernel\Audit\Watch;
use Acadly\Kernel\Events\Outbox;
use Acadly\Kernel\Identity\AccountStatus;
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
 *  - the place must be in force;
 *  - the account must not be disabled (an invited account may hold a role
 *    before it is first used), and an end date must be in the future.
 */
final class AssignRole
{
    public function __construct(
        private readonly Access $access,
        private readonly PermissionCatalogue $catalogue,
        private readonly Clock $clock,
        private readonly Audit $audit,
        private readonly Outbox $outbox,
        private readonly ScopeHierarchy $hierarchy,
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
                $this->audit->record(KernelAudit::Refused, outcome: Outcome::Refused, refusal: 'role_exceeds_assigner:'.$key,
                    actor: $this->audit->actorFor($by), at: $at, meta: ['role' => $role->key, 'to' => $to->id]);

                throw new AuthorizationException('A role cannot include a permission that the assigner does not hold at this scope.');
            }
        }

        $now = $this->clock->now();

        if (! $this->hierarchy->exists($at)) {
            throw ValidationException::withMessages(['scope' => ['This part of the school does not exist or has ended.']]);
        }

        if ($to->status === AccountStatus::Disabled) {
            throw ValidationException::withMessages(['user' => ['The account is disabled.']]);
        }

        if ($endsAt !== null && $endsAt <= $now) {
            throw ValidationException::withMessages(['ends_at' => ['The end date must be in the future.']]);
        }

        $assignment = DB::transaction(function () use ($by, $to, $role, $at, $now, $endsAt, $reason): RoleAssignment {
            $assignment = RoleAssignment::query()->create([
                'user_id' => $to->id,
                'role_id' => $role->id,
                'scope_type' => $at->type,
                'scope_id' => $at->id,
                'starts_at' => $now,
                'ends_at' => $endsAt,
                'granted_by' => $by->id,
                'reason' => $reason,
            ]);

            $this->audit->record(KernelAudit::RoleAssigned, subject: $assignment, changes: Watch::created($assignment)->diff(),
                reason: $reason, actor: $this->audit->actorFor($by), at: $at, meta: ['to' => $to->id, 'role' => $role->key]);

            $this->outbox->record(new RoleAssigned(
                $assignment->id, $to->id, $role->key, $at->type->value, $at->id,
                $this->includesSight($role), $by->id,
            ));

            return $assignment;
        });

        $this->access->forget($to);

        return $assignment;
    }

    private function includesSight(Role $role): bool
    {
        foreach ($role->permissionKeys() as $key) {
            if ($this->catalogue->has($key) && $this->catalogue->get($key)->isSight()) {
                return true;
            }
        }

        return false;
    }
}
