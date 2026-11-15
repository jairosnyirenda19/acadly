<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint;

use Acadly\Kernel\Blueprint\Compiled\CompiledJourney;
use Acadly\Kernel\Blueprint\Journey\JourneyLinter;
use InvalidArgumentException;
use RuntimeException;

/**
 * Compiles every declaration in the build and checks it
 * (`blueprint:compile`, `blueprint:lint`, `acadly:lint`).
 *
 * A problem is reported, never fixed silently: a released version whose
 * declaration changed, two declarations of one ref, a compiled file with no
 * declaration, and everything the journey linter finds.
 */
final class BlueprintCompiler
{
    /** @var list<CompiledJourney> */
    private array $compiled = [];

    /** @var list<string> */
    private array $problems = [];

    public function __construct(
        Declarations $declarations,
        JourneyLinter $linter,
        private readonly Lock $lock,
    ) {
        $this->problems = $declarations->problems();
        $seen = [];

        foreach ($declarations->journeys() as $declaration) {
            $ref = (string) $declaration->ref;

            if (isset($seen[$ref])) {
                $this->problems[] = "{$ref}: the version is declared twice.";

                continue;
            }

            $seen[$ref] = true;

            try {
                $journey = $declaration->compile();
            } catch (InvalidArgumentException $e) {
                $this->problems[] = $e->getMessage();

                continue;
            }

            $locked = $lock->hashOf($journey->ref);

            if ($locked !== null && ! hash_equals($locked, $journey->hash())) {
                $this->problems[] = "{$ref}: the version is released and its declaration has changed. Make a new version.";
            }

            $this->problems = [...$this->problems, ...$linter->problems($journey)];
            $this->compiled[] = $journey;
        }

        foreach (array_keys($lock->all()) as $released) {
            if (! isset($seen[$released])) {
                $this->problems[] = "{$released}: the version is released, and its declaration is missing.";
            }
        }
    }

    /** @return list<CompiledJourney> */
    public function compiled(): array
    {
        return $this->compiled;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * Problems with the compiled files in a folder: one that is stale, one
     * with no declaration, one that is missing.
     *
     * @return list<string>
     */
    public function staleFiles(string $folder): array
    {
        $problems = [];
        $expected = [];

        foreach ($this->compiled as $journey) {
            $file = $folder.'/'.$journey->ref.'.json';
            $expected[basename($file)] = true;

            if (! is_file($file)) {
                $problems[] = "{$journey->ref}: the compiled file is missing. Run blueprint:compile.";
            } elseif (str_replace("\r\n", "\n", (string) file_get_contents($file)) !== $journey->json()) {
                $problems[] = "{$journey->ref}: the compiled file is out of date. Run blueprint:compile.";
            }
        }

        foreach (is_dir($folder) ? (glob($folder.'/*.json') ?: []) : [] as $file) {
            if (! isset($expected[basename($file)])) {
                $problems[] = basename($file).': the compiled file has no declaration.';
            }
        }

        return $problems;
    }

    /**
     * Writes the compiled files. Refuses while there are problems.
     *
     * @return list<string> the refs written
     */
    public function write(string $folder): array
    {
        if ($this->problems !== []) {
            throw new RuntimeException('The blueprints have problems. Nothing was written.');
        }

        if (! is_dir($folder) && ! mkdir($folder, 0775, true) && ! is_dir($folder)) {
            throw new RuntimeException('The compiled folder cannot be created.');
        }

        $written = [];

        foreach ($this->compiled as $journey) {
            $file = $folder.'/'.$journey->ref.'.json';

            if (! is_file($file) || (string) file_get_contents($file) !== $journey->json()) {
                file_put_contents($file, $journey->json());
                $written[] = (string) $journey->ref;
            }
        }

        return $written;
    }

    public function lock(): Lock
    {
        return $this->lock;
    }
}
