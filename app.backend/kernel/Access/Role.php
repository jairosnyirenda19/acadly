<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

use Acadly\Kernel\Audit\Auditable;
use Acadly\Kernel\Audit\FieldClass;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property CarbonImmutable|null $retired_at
 */
final class Role extends Model implements Auditable
{
    use HasUuids;

    /**
     * The key of the one system role: it holds every permission, kept so by
     * acadly:sync, and cannot be edited. The key identifies that record; it is
     * never a test of power. Power is asked of permissions (Access).
     */
    public const string ADMINISTRATOR = 'administrator';

    protected $table = 'access_roles';

    protected $fillable = ['key', 'name', 'description', 'is_system'];

    public function auditType(): string
    {
        return 'role';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditFields(): array
    {
        return ['name' => FieldClass::Plain, 'description' => FieldClass::Plain];
    }

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

    /**
     * Roles in force: retired roles keep their history and are offered nowhere.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'retired_at' => 'immutable_datetime'];
    }
}
