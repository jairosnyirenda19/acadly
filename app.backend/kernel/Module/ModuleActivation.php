<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Module\Licence\Licences;
use Illuminate\Support\Facades\DB;

/**
 * Answers "is this module on, here?" (docs/architecture/01-foundations.md §6).
 *
 *     installed ∧ offered ∧ licensed ∧ enabled
 *
 * Core domains and the kernel's own areas are always on. A module the profile
 * requires is on without a switch. Everything else is on only after the
 * school switched it on. One instance per request: it remembers what it read.
 */
final class ModuleActivation
{
    /** Areas of the kernel itself. Never switched off. */
    public const array KERNEL_AREAS = ['identity', 'access', 'audit', 'modules'];

    /** @var array<string, bool>|null module key => switched on */
    private ?array $switches = null;

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ModuleOffering $offering,
        private readonly Licences $licences,
    ) {}

    public function state(string $key, ?ScopeRef $at = null): ModuleState
    {
        if (in_array($key, self::KERNEL_AREAS, true)) {
            return ModuleState::Active;
        }

        if (! $this->modules->has($key)) {
            return ModuleState::NotInstalled;
        }

        $module = $this->modules->get($key);
        $at ??= ScopeRef::institution();

        if ($module->tier() === Tier::Core) {
            return ModuleState::Active;
        }

        if (! $this->offering->offers($module, $at)) {
            return ModuleState::NotOffered;
        }

        if (! $this->licences->current()->includes($key)) {
            return ModuleState::NotLicensed;
        }

        if ($this->offering->requires($module, $at)) {
            return ModuleState::Active;
        }

        return ($this->switches()[$key] ?? false) ? ModuleState::Active : ModuleState::SwitchedOff;
    }

    public function isActive(string $key, ?ScopeRef $at = null): bool
    {
        return $this->state($key, $at)->isActive();
    }

    /** Whether the school has switched the module on, whatever else holds. */
    public function isSwitchedOn(string $key): bool
    {
        return $this->switches()[$key] ?? false;
    }

    /** @return list<string> the keys of every module that is on at the scope */
    public function activeKeys(?ScopeRef $at = null): array
    {
        $keys = [];

        foreach ($this->modules->all() as $module) {
            if ($this->isActive($module->key(), $at)) {
                $keys[] = $module->key();
            }
        }

        return $keys;
    }

    /** Forget what this request has read, after a switch. */
    public function forget(): void
    {
        $this->switches = null;
        $this->licences->forget();
    }

    /** @return array<string, bool> */
    private function switches(): array
    {
        if ($this->switches !== null) {
            return $this->switches;
        }

        // The newest switch row per module is in force; older rows are its history.
        $rows = DB::select(
            'SELECT DISTINCT ON (module_key) module_key, enabled FROM module_switches ORDER BY module_key, switched_at DESC, id DESC'
        );

        $switches = [];

        foreach ($rows as $row) {
            if (is_object($row) && is_string($row->module_key ?? null)) {
                $switches[$row->module_key] = ($row->enabled ?? false) === true;
            }
        }

        return $this->switches = $switches;
    }
}
