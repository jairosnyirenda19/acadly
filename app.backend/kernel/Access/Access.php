<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

use Acadly\Kernel\Identity\User;
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
 *  2. The permission is in this build's catalogue.
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

    /** @throws AuthorizationException when the check fails; rendered as 403 access_refused */
    public function check(?Authenticatable $who, Permission $permission, ?ScopeRef $at = null): void
    {
        if (! $this->allows($who, $permission, $at)) {
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
