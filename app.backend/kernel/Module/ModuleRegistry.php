<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module;

use LogicException;

/**
 * Every manifest in the build, checked once when it is filled.
 *
 * Three rules are enforced here, so a broken graph stops the boot and the
 * linter rather than surfacing as a strange failure later:
 *  - a key is unique;
 *  - a manifest requires only manifests that are registered;
 *  - a Core domain never requires an Optional module, and nothing is circular.
 */
final class ModuleRegistry
{
    /** @var array<class-string<Module>, Module> */
    private array $byClass = [];

    /** @var array<string, Module> */
    private array $byKey = [];

    /** @param iterable<Module> $modules */
    public function __construct(iterable $modules = [])
    {
        foreach ($modules as $module) {
            $this->add($module);
        }

        $this->assertSound();
    }

    /** @return list<Module> */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    public function has(string $key): bool
    {
        return isset($this->byKey[$key]);
    }

    public function get(string $key): Module
    {
        return $this->byKey[$key] ?? throw new LogicException("No module is registered as [{$key}].");
    }

    private function add(Module $module): void
    {
        $key = $module->key();

        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new LogicException("Module key [{$key}] must be lower-case letters, digits and underscores.");
        }

        if (isset($this->byKey[$key])) {
            throw new LogicException("Two modules use the key [{$key}].");
        }

        $this->byKey[$key] = $module;
        $this->byClass[$module::class] = $module;
    }

    private function assertSound(): void
    {
        foreach ($this->byClass as $class => $module) {
            foreach ($module->requires() as $required) {
                $other = $this->byClass[$required] ?? throw new LogicException(
                    "[{$class}] requires [{$required}], which is not registered."
                );

                if ($module->tier() === Tier::Core && $other->tier() === Tier::Optional) {
                    throw new LogicException(
                        "Core domain [{$module->key()}] cannot require the optional module [{$other->key()}]."
                    );
                }
            }

            $this->assertNoCycle($class, []);
        }
    }

    /**
     * @param  class-string<Module>  $class
     * @param  list<class-string<Module>>  $path
     */
    private function assertNoCycle(string $class, array $path): void
    {
        if (in_array($class, $path, true)) {
            $names = array_map(fn (string $c): string => $this->byClass[$c]->key(), [...$path, $class]);

            throw new LogicException('Modules require each other in a circle: '.implode(' → ', $names).'.');
        }

        foreach ($this->byClass[$class]->requires() as $required) {
            $this->assertNoCycle($required, [...$path, $class]);
        }
    }
}
