<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Audit\Outcome;
use Acadly\Kernel\Audit\Watch;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a role or changes one: its name, its description and its
 * permissions. A role is a name; its permissions are its power, and a change
 * applies at once to everyone who holds it.
 *
 * Guard rails:
 *  - the editor holds "Create and edit roles" for the whole school;
 *  - the editor may add only permissions they hold for the whole school;
 *  - the system role cannot be changed;
 *  - a change may not leave nobody active who can give roles for the
 *    whole school.
 */
final class SaveRole
{
    public function __construct(
        private readonly Access $access,
        private readonly PermissionCatalogue $catalogue,
        private readonly Audit $audit,
    ) {}

    /** @param  list<string>  $permissions  permission keys */
    public function handle(User $by, ?Role $role, string $name, ?string $description, array $permissions): Role
    {
        $this->access->check($by, AccessPermission::ManageRoles);

        if ($role !== null && $role->is_system) {
            throw ValidationException::withMessages(['role' => ['The system role holds every permission and cannot be changed.']]);
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 60) {
            throw ValidationException::withMessages(['name' => ['Give the role a name of up to 60 characters.']]);
        }

        $same = Role::query()->inForce()->whereRaw('lower(name) = ?', [mb_strtolower($name)]);

        if ($role !== null) {
            $same->whereKeyNot($role->id);
        }

        if ($same->exists()) {
            throw ValidationException::withMessages(['name' => ['Another role already has this name.']]);
        }

        $wanted = array_values(array_unique($permissions));

        foreach ($wanted as $key) {
            if (! $this->catalogue->has($key)) {
                throw ValidationException::withMessages(['permissions' => ['A permission is not available in this school.']]);
            }
        }

        $before = $role !== null ? $role->permissionKeys() : [];

        foreach (array_diff($wanted, $before) as $key) {
            if (! $this->access->allows($by, $this->catalogue->get($key))) {
                $this->audit->record(KernelAudit::Refused, outcome: Outcome::Refused, refusal: 'role_exceeds_editor:'.$key,
                    actor: $this->audit->actorFor($by), meta: ['permission' => $key]);

                throw new AuthorizationException('A role cannot be given a permission that the editor does not hold.');
            }
        }

        $assign = AccessPermission::AssignRoles->key();

        if ($role !== null && in_array($assign, $before, true) && ! in_array($assign, $wanted, true)
            && $this->access->holders(AccessPermission::AssignRoles) > 0
            && $this->access->holders(AccessPermission::AssignRoles, exceptRole: $role->id) === 0) {
            throw ValidationException::withMessages(['permissions' => ['Nobody would be left who can give roles for the whole school. Give that permission to another role first.']]);
        }

        return DB::transaction(function () use ($by, $role, $name, $description, $wanted, $before): Role {
            $record = $role ?? new Role(['key' => self::key($name), 'is_system' => false]);
            $watch = $role !== null ? Watch::of($record) : null;
            $record->fill(['name' => $name, 'description' => $description !== null && trim($description) !== '' ? trim($description) : null])->save();

            DB::table('access_role_permissions')->where('role_id', $record->id)->whereNotIn('permission_key', $wanted)->delete();
            $record->grant(array_map(fn (string $key) => $this->catalogue->get($key), $wanted));

            $added = array_values(array_diff($wanted, $before));
            $removed = array_values(array_diff($before, $wanted));

            $this->audit->record($role === null ? KernelAudit::RoleCreated : KernelAudit::RoleChanged, subject: $record,
                changes: ($watch ?? Watch::created($record))->diff(), actor: $this->audit->actorFor($by),
                meta: ['role' => $record->key, 'name' => $name, 'added' => implode(',', $added), 'removed' => implode(',', $removed)]);

            return $record;
        });
    }

    private static function key(string $name): string
    {
        return Str::limit(Str::snake(Str::ascii($name)), 60, '').'_'.substr(Ids::next(), -6);
    }
}
