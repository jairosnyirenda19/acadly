<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents;

use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Module\ModuleRegistry;
use LogicException;

/**
 * Every document kind in the build, from the manifests. Checked when it is
 * built: a key has the form `<module>.<kind>`, starts with the module key and
 * is unique; a kind accepts at least one type, has a size limit and a real
 * owner resolver. `problems()` adds the checks that need the permission
 * catalogue, for `acadly:lint`.
 */
final class DocumentCatalogue
{
    /** @var array<string, DocumentKind> */
    private array $kinds = [];

    public function __construct(ModuleRegistry $modules)
    {
        foreach ($modules->all() as $module) {
            foreach ($module->documentKinds() as $kind) {
                $this->add($module->key(), $kind);
            }
        }
    }

    public function has(string $key): bool
    {
        return isset($this->kinds[$key]);
    }

    public function get(string $key): DocumentKind
    {
        return $this->kinds[$key] ?? throw new LogicException("[{$key}] is not a document kind in this build.");
    }

    /** @return array<string, DocumentKind> */
    public function all(): array
    {
        return $this->kinds;
    }

    /** @return list<string> */
    public function problems(PermissionCatalogue $permissions): array
    {
        $problems = [];

        foreach ($this->kinds as $key => $kind) {
            foreach ([$kind->readPermission(), $kind->writePermission()] as $permission) {
                if (! $permissions->has($permission->key())) {
                    $problems[] = "{$key}: [{$permission->key()}] is not a permission in this build.";
                }
            }
        }

        return $problems;
    }

    private function add(string $owner, DocumentKind $kind): void
    {
        $key = $kind->key();

        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key) !== 1 || ! str_starts_with($key, $owner.'.')) {
            throw new LogicException("Document kind [{$key}] must have the form {$owner}.kind.");
        }

        if (isset($this->kinds[$key])) {
            throw new LogicException("Document kind [{$key}] is declared twice.");
        }

        if ($kind->accepts() === [] || $kind->maxBytes() <= 0 || trim($kind->label()) === '') {
            throw new LogicException("Document kind [{$key}] needs a label, accepted types and a size limit.");
        }

        if ($kind->isPublic() && $kind->classification() !== FieldClass::Plain) {
            throw new LogicException("Document kind [{$key}] is public, so it must be plain.");
        }

        if (! is_subclass_of($kind->owner(), DocumentOwner::class)) {
            throw new LogicException("Document kind [{$key}] names an owner resolver that is not one.");
        }

        $this->kinds[$key] = $kind;
    }
}
