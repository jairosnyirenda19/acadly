<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

use Acadly\Kernel\Module\ModulePermission;
use Acadly\Kernel\Module\ModuleRegistry;
use LogicException;

/**
 * Every permission in the build: the kernel's own, then each module's, from
 * its manifest. Built once and checked when it is built, so a broken
 * declaration stops the boot and the lint.
 *
 * Rules: a key has the form `<owner>.<function>`, starts with the key of the
 * module that declares it, is unique, and has a label and a sentence.
 */
final class PermissionCatalogue
{
    public const string KERNEL_OWNER = 'access';

    /** @var array<string, Permission> */
    private array $byKey = [];

    /** @var array<string, string> permission key => owner key */
    private array $owners = [];

    public function __construct(ModuleRegistry $modules)
    {
        $this->addAll(self::KERNEL_OWNER, AccessPermission::cases());
        $this->addAll('modules', ModulePermission::cases());

        foreach ($modules->all() as $module) {
            $this->addAll($module->key(), $module->permissions());
        }
    }

    /** @return array<string, Permission> */
    public function all(): array
    {
        return $this->byKey;
    }

    public function has(string $key): bool
    {
        return isset($this->byKey[$key]);
    }

    public function get(string $key): Permission
    {
        return $this->byKey[$key] ?? throw new LogicException("[{$key}] is not a permission in this build.");
    }

    public function ownerOf(Permission $permission): string
    {
        return $this->owners[$permission->key()] ?? throw new LogicException("[{$permission->key()}] is not a permission in this build.");
    }

    /** @param  list<Permission>  $permissions */
    private function addAll(string $owner, array $permissions): void
    {
        foreach ($permissions as $permission) {
            $key = $permission->key();

            if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $key) !== 1) {
                throw new LogicException("Permission key [{$key}] must have the form owner.function.");
            }

            if (! str_starts_with($key, $owner.'.')) {
                throw new LogicException("Permission [{$key}] is declared by [{$owner}] and must start with [{$owner}.].");
            }

            if (isset($this->byKey[$key])) {
                throw new LogicException("Permission [{$key}] is declared twice.");
            }

            if (trim($permission->label()) === '' || trim($permission->help()) === '') {
                throw new LogicException("Permission [{$key}] needs a label and a sentence.");
            }

            $this->byKey[$key] = $permission;
            $this->owners[$key] = $owner;
        }
    }
}
