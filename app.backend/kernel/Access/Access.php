<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Audit\Outcome;
use Acadly\Kernel\Http\Problem;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Support\Clock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The one access check (docs/architecture/03-deployment-campus-access.md §5.1).
 * Deny by default.
 *
 *  1. The account exists and is active.
 *  2. The permission is in this build's catalogue, and the module that
 *     declares it is on at the target scope (entitlement, rule D7).
 *  3. The account holds the permission, through an active role assignment,
 *     at the target scope or at a scope that contains it.
 *
 * Record-level rules (a teacher's own classes, a guardian's own children) are
 * added by the modules that own those records. Whether a module is licensed
 * and switched on is added with the licence. Facts about the world (a period
 * is closed, a fee is unpaid) are journey gates, not permissions.
 */
final class Access
{
    /** @var array<string, list<Grant>> account id => grants, for this request */
    private array $grants = [];

    public function __construct(
        private readonly PermissionCatalogue $catalogue,
        private readonly ScopeHierarchy $hierarchy,
        private readonly Clock $clock,
        private readonly Audit $audit,
        private readonly ModuleActivation $modules,
    ) {}

    public function allows(?Authenticatable $who, Permission $permission, ?ScopeRef $at = null): bool
    {
        if (! $this->catalogue->has($permission->key())) {
            throw new LogicException("[{$permission->key()}] is not a permission in this build.");
        }

        if (! $who instanceof User || ! $who->isActive()) {
            return false;
        }

        $at ??= ScopeRef::institution();

        if (! $this->modules->isActive($this->catalogue->ownerOf($permission), $at)) {
            return false;
        }

        $places = [$at, ...$this->hierarchy->ancestors($at)];

        foreach ($this->grants($who) as $grant) {
            if ($grant->permission !== $permission->key()) {
                continue;
            }

            foreach ($places as $place) {
                if ($grant->scope->equals($place)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Refuses with an exception, rendered as 403 access_refused. A refusal is
     * an audit line: no module writes its own.
     *
     * @throws AuthorizationException
     */
    public function check(?Authenticatable $who, Permission $permission, ?ScopeRef $at = null): void
    {
        if (! $this->allows($who, $permission, $at)) {
            $owner = $this->catalogue->has($permission->key()) ? $this->catalogue->ownerOf($permission) : '';
            $moduleOff = $owner !== '' && ! $this->modules->isActive($owner, $at);

            $this->audit->record(KernelAudit::Refused, outcome: Outcome::Refused,
                refusal: $moduleOff ? 'module:'.$owner : 'permission:'.$permission->key(),
                actor: $this->audit->actorFor($who), at: $at, meta: ['permission' => $permission->key()]);

            if ($moduleOff) {
                throw Problem::moduleInactive();
            }

            throw new AuthorizationException('Access is refused.');
        }
    }

    /**
     * Every permission the account holds, with its scope, through assignments
     * in force now.
     *
     * @return list<Grant>
     */
    public function grants(User $user): array
    {
        return $this->grants[$user->id] ??= $this->load($user);
    }

    /**
     * How many active accounts hold a permission at a scope, through a role
     * held there or at a scope that contains it. Power comes from permissions,
     * never from the name of a role: this is how "is anyone left who can give
     * roles for the whole school" is asked.
     *
     * @param  string|null  $except  an assignment to leave out, as if it had ended
     */
    public function holders(Permission $permission, ?ScopeRef $at = null, ?string $except = null): int
    {
        $at ??= ScopeRef::institution();
        $places = [$at, ...$this->hierarchy->ancestors($at)];
        $now = $this->clock->now();

        return DB::table('access_role_assignments as a')
            ->join('access_role_permissions as rp', 'rp.role_id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('rp.permission_key', $permission->key())
            ->where('u.status', AccountStatus::Active->value)
            ->where('a.starts_at', '<=', $now)
            ->where(fn (Builder $q): Builder => $q->whereNull('a.ends_at')->orWhere('a.ends_at', '>', $now))
            ->when($except !== null, fn (Builder $q): Builder => $q->where('a.id', '!=', $except))
            ->where(function (Builder $q) use ($places): void {
                foreach ($places as $place) {
                    $q->orWhere(fn (Builder $one): Builder => $one->where('a.scope_type', $place->type->value)
                        ->where(fn (Builder $id): Builder => $place->id === null ? $id->whereNull('a.scope_id') : $id->where('a.scope_id', $place->id)));
                }
            })
            ->distinct()
            ->count('a.user_id');
    }

    /** Drop what this request remembers about an account, after its roles change. */
    public function forget(User $user): void
    {
        unset($this->grants[$user->id]);
    }

    /** @return list<Grant> */
    private function load(User $user): array
    {
        $now = $this->clock->now();

        $rows = DB::table('access_role_assignments as a')
            ->join('access_role_permissions as rp', 'rp.role_id', '=', 'a.role_id')
            ->where('a.user_id', $user->id)
            ->where('a.starts_at', '<=', $now)
            ->where(fn (Builder $q): Builder => $q->whereNull('a.ends_at')->orWhere('a.ends_at', '>', $now))
            ->orderBy('rp.permission_key')
            ->get(['rp.permission_key', 'a.scope_type', 'a.scope_id']);

        $grants = [];

        foreach ($rows as $row) {
            $key = $row->permission_key;
            $type = is_string($row->scope_type) ? ScopeType::tryFrom($row->scope_type) : null;
            $id = is_string($row->scope_id) ? $row->scope_id : null;

            if (! is_string($key) || $type === null || ! $this->catalogue->has($key)) {
                continue;
            }

            $grants[] = new Grant($key, ScopeRef::of($type, $id));
        }

        return $grants;
    }
}
