<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint;

use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\ModuleRegistry;
use ReflectionClass;
use Throwable;

/**
 * Finds the journey declarations of every module in the build: the files
 * `Journeys/<Name>/V<YYYY>_<N>.php` next to each manifest. Used only by the
 * compiler and the linter. A declaration is never loaded on a request.
 */
final class Declarations
{
    /** @var list<JourneyBlueprint> */
    private array $journeys = [];

    /** @var list<string> */
    private array $problems = [];

    public function __construct(ModuleRegistry $modules)
    {
        foreach ($modules->all() as $module) {
            $this->collect($module);
        }
    }

    /** @return list<JourneyBlueprint> */
    public function journeys(): array
    {
        return $this->journeys;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    private function collect(Module $module): void
    {
        $file = (new ReflectionClass($module))->getFileName();
        $folder = $file !== false ? dirname($file).'/Journeys' : null;
        $listed = $module->journeys();
        $found = [];

        foreach ($folder !== null && is_dir($folder) ? (glob($folder.'/*/V*.php') ?: []) : [] as $path) {
            $name = basename(dirname($path)).'/'.basename($path);

            try {
                $declaration = (static fn (string $path): mixed => require $path)($path);
            } catch (Throwable $e) {
                $this->problems[] = "{$module->key()} {$name}: the declaration cannot be read ({$e->getMessage()}).";

                continue;
            }

            if (! $declaration instanceof JourneyBlueprint) {
                $this->problems[] = "{$module->key()} {$name}: the file does not return a journey blueprint.";

                continue;
            }

            $ref = $declaration->ref;
            $expected = 'V'.str_replace('.', '_', $ref->version).'.php';

            if (basename($path) !== $expected) {
                $this->problems[] = "{$module->key()} {$name}: version {$ref->version} belongs in {$expected}.";
            }

            if ($ref->owner() !== $module->key()) {
                $this->problems[] = "{$module->key()} {$name}: [{$ref}] must start with [{$module->key()}.].";
            }

            if (! in_array($ref->key, $listed, true)) {
                $this->problems[] = "{$module->key()} {$name}: [{$ref->key}] is not listed in the manifest's journeys().";
            }

            $found[$ref->key] = true;
            $this->journeys[] = $declaration;
        }

        foreach ($listed as $key) {
            if (! isset($found[$key])) {
                $this->problems[] = "{$module->key()}: the manifest lists the journey [{$key}], which has no declaration.";
            }
        }
    }
}
