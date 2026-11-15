<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

use Acadly\Kernel\Module\ModuleRegistry;
use LogicException;

/**
 * Every audit action in the build: the kernel's own, then each module's,
 * from its manifest. Checked when it is built, like the permission catalogue.
 *
 * Rules: a key has the form `<owner>.<what_happened>`, starts with the key of
 * the module that declares it, is unique, and has a sentence.
 */
final class AuditCatalogue
{
    /** Owners of the kernel's own actions. */
    public const array KERNEL_OWNERS = ['identity', 'access', 'audit'];

    /** @var array<string, AuditAction> */
    private array $byKey = [];

    /** @var array<string, string> action key => owner key */
    private array $owners = [];

    public function __construct(ModuleRegistry $modules)
    {
        foreach (KernelAudit::cases() as $action) {
            $this->add(explode('.', $action->key())[0], $action, self::KERNEL_OWNERS);
        }

        foreach ($modules->all() as $module) {
            foreach ($module->auditActions() as $action) {
                $this->add($module->key(), $action, [$module->key()]);
            }
        }
    }

    /** @return array<string, AuditAction> */
    public function all(): array
    {
        return $this->byKey;
    }

    public function has(string $key): bool
    {
        return isset($this->byKey[$key]);
    }

    public function ownerOf(AuditAction $action): string
    {
        return $this->owners[$action->key()] ?? throw new LogicException("[{$action->key()}] is not an audit action in this build.");
    }

    /** @param  list<string>  $allowedOwners */
    private function add(string $owner, AuditAction $action, array $allowedOwners): void
    {
        $key = $action->key();

        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new LogicException("Audit action [{$key}] must have the form owner.what_happened.");
        }

        if (! in_array($owner, $allowedOwners, true) || ! str_starts_with($key, $owner.'.')) {
            throw new LogicException("Audit action [{$key}] must start with the key of the module that declares it.");
        }

        if (isset($this->byKey[$key])) {
            throw new LogicException("Audit action [{$key}] is declared twice.");
        }

        if (trim($action->sentence()) === '') {
            throw new LogicException("Audit action [{$key}] needs a sentence.");
        }

        $this->byKey[$key] = $action;
        $this->owners[$key] = $owner;
    }
}
