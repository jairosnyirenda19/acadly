<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module;

use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Audit\AuditAction;

/**
 * The manifest of one Core domain or one Module (docs/architecture/01-foundations.md §5).
 *
 * A manifest is a declaration. The kernel reads it at boot and the linters read
 * it in CI. Later stages add permissions, topics, journeys, search sources and
 * audit actions to this contract.
 */
interface Module
{
    /** Short, unique, lower-case key: 'attendance'. */
    public function key(): string;

    public function tier(): Tier;

    /**
     * The manifests this one cannot work without.
     *
     * @return list<class-string<Module>>
     */
    public function requires(): array;

    /**
     * The permissions this module declares. Each key starts with the module key.
     *
     * @return list<Permission>
     */
    public function permissions(): array;

    /**
     * The audit actions this module declares. Each key starts with the module key.
     *
     * @return list<AuditAction>
     */
    public function auditActions(): array;
}
