<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint;

use Acadly\Kernel\Blueprint\Journey\JourneyBlueprint;
use Acadly\Kernel\Blueprint\Profile\ProfileBlueprint;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\ModuleRegistry;
use ReflectionClass;
use Throwable;

/**
 * Finds the declarations of the build: the journeys of every module, in
 * `Journeys/<Name>/V<YYYY>_<N>.php` next to each manifest, and the profiles,
 * in `blueprints/profiles/<Name>/V<YYYY>_<N>.php`. Used only by the compiler
 * and the linter. A declaration is never loaded on a request.
 */
final class Declarations
{
    /** @var list<JourneyBlueprint> */
    private array $journeys = [];

    /** @var list<ProfileBlueprint> */
    private array $profiles = [];

    /** @var list<string> */
    private array $problems = [];

    public function __construct(ModuleRegistry $modules, ?string $profileFolder = null)
    {
        foreach ($modules->all() as $module) {
            $this->collect($module);
        }

        foreach ($profileFolder !== null && is_dir($profileFolder) ? (glob($profileFolder.'/*/V*.php') ?: []) : [] as $path) {
            $this->collectProfile($path);
        }
    }

    /** @return list<ProfileBlueprint> */
    public function profiles(): array
    {
        return $this->profiles;
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

    private function collectProfile(string $path): void
    {
        $name = 'profiles '.basename(dirname($path)).'/'.basename($path);

        try {
            $declaration = (static fn (string $path): mixed => require $path)($path);
        } catch (Throwable $e) {
            $this->problems[] = "{$name}: the declaration cannot be read ({$e->getMessage()}).";

            return;
        }

        if (! $declaration instanceof ProfileBlueprint) {
            $this->problems[] = "{$name}: the file does not return a profile blueprint.";

            return;
        }

        $expected = 'V'.str_replace('.', '_', $declaration->ref->version).'.php';

        if (basename($path) !== $expected) {
            $this->problems[] = "{$name}: version {$declaration->ref->version} belongs in {$expected}.";
        }

        $this->profiles[] = $declaration;
    }
}
