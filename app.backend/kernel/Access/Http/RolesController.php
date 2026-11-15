<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Actions\RemoveRole;
use Acadly\Kernel\Access\Actions\SaveRole;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Support\Clock;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Roles screen (the approved design): every role with its permissions,
 * and the permissions of the modules that are on, grouped by area.
 */
final class RolesController
{
    private const array AREAS = [
        'access' => 'Access', 'identity' => 'Accounts', 'modules' => 'Modules', 'notify' => 'Notices',
        'documents' => 'Documents', 'audit' => 'Audit trail', 'journeys' => 'Processes',
    ];

    public function __construct(private readonly Access $access) {}

    /**
     * @response array{roles: list<array{id: string, name: string, description: string|null, system: bool, people: int, permissions: list<string>}>, groups: list<array{name: string, permissions: list<array{key: string, label: string, help: string, sight: bool, yours: bool}>}>}
     */
    public function index(Request $request, PermissionCatalogue $catalogue, ModuleRegistry $modules, ModuleActivation $activation, Clock $clock): JsonResponse
    {
        $viewer = self::user($request);
        $this->access->check($viewer, AccessPermission::ManageRoles);

        $now = $clock->now();
        $people = DB::table('access_role_assignments as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('u.status', '!=', AccountStatus::Disabled->value)->where('a.starts_at', '<=', $now)
            ->where(fn (Builder $q): Builder => $q->whereNull('a.ends_at')->orWhere('a.ends_at', '>', $now))
            ->groupBy('a.role_id')->selectRaw('a.role_id, count(distinct a.user_id) as people')->pluck('people', 'role_id')->all();

        $roles = Role::query()->inForce()->orderByDesc('is_system')->orderBy('name')->get()->map(fn (Role $role): array => [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'system' => $role->is_system,
            'people' => is_numeric($people[$role->id] ?? null) ? (int) $people[$role->id] : 0,
            'permissions' => $role->permissionKeys(),
        ])->all();

        $groups = [];

        foreach ($catalogue->all() as $key => $permission) {
            $owner = $catalogue->ownerOf($permission);

            if (! $activation->isActive($owner)) {
                continue;
            }

            $name = self::AREAS[$owner] ?? ($modules->has($owner) ? $modules->get($owner)->name() : $owner);
            $groups[$name][] = [
                'key' => $key,
                'label' => $permission->label(),
                'help' => $permission->help(),
                'sight' => $permission->isSight(),
                'yours' => $this->access->allows($viewer, $permission),
            ];
        }

        $out = [];

        foreach ($groups as $name => $permissions) {
            $out[] = ['name' => $name, 'permissions' => $permissions];
        }

        return response()->json(['roles' => array_values($roles), 'groups' => $out]);
    }

    /** Creates a role: `{name, description?, permissions: [keys]}`. */
    public function store(Request $request, SaveRole $save, PermissionCatalogue $catalogue, ModuleRegistry $modules, ModuleActivation $activation, Clock $clock): JsonResponse
    {
        $viewer = self::user($request);
        $this->access->check($viewer, AccessPermission::ManageRoles);
        $save->handle($viewer, null, $request->string('name')->toString(), self::text($request->input('description')), self::keys($request->input('permissions')));

        return $this->index($request, $catalogue, $modules, $activation, $clock);
    }

    /** Changes a role: `{name, description?, permissions: [keys]}`. */
    public function update(Request $request, string $id, SaveRole $save, PermissionCatalogue $catalogue, ModuleRegistry $modules, ModuleActivation $activation, Clock $clock): JsonResponse
    {
        $viewer = self::user($request);
        $this->access->check($viewer, AccessPermission::ManageRoles);
        $role = Role::query()->inForce()->find($id) ?? throw (new ModelNotFoundException)->setModel(Role::class);
        $save->handle($viewer, $role, $request->string('name')->toString(), self::text($request->input('description')), self::keys($request->input('permissions')));

        return $this->index($request, $catalogue, $modules, $activation, $clock);
    }

    /** Removes a role nobody holds: retired when it was held before, deleted when never. */
    public function destroy(Request $request, string $id, RemoveRole $remove, PermissionCatalogue $catalogue, ModuleRegistry $modules, ModuleActivation $activation, Clock $clock): JsonResponse
    {
        $viewer = self::user($request);
        $this->access->check($viewer, AccessPermission::ManageRoles);
        $role = Role::query()->inForce()->find($id) ?? throw (new ModelNotFoundException)->setModel(Role::class);
        $remove->handle($viewer, $role);

        return $this->index($request, $catalogue, $modules, $activation, $clock);
    }

    /** @return list<string> */
    private static function keys(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
