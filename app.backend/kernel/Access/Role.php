<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A named bundle of permissions. A role grants nothing until it is assigned
 * to an account at a scope.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 */
final class Role extends Model
{
    use HasUuids;

    /** The system role that holds every permission. It cannot be edited. */
    public const string ADMINISTRATOR = 'administrator';

    protected $table = 'access_roles';

    protected $fillable = ['key', 'name', 'description', 'is_system'];

    /** @return list<string> */
    public function permissionKeys(): array
    {
        $keys = DB::table('access_role_permissions')
            ->where('role_id', $this->id)
            ->orderBy('permission_key')
            ->pluck('permission_key')
            ->all();

        return array_values(array_filter($keys, is_string(...)));
    }

    /** @param  list<Permission>  $permissions */
    public function grant(array $permissions): void
    {
        DB::table('access_role_permissions')->insertOrIgnore(array_map(
            fn (Permission $permission): array => ['role_id' => $this->id, 'permission_key' => $permission->key()],
            $permissions,
        ));
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }
}
