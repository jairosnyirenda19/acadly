<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint;

use Acadly\Kernel\Blueprint\Compiled\CompiledBlueprint;
use Acadly\Kernel\Blueprint\Compiled\CompiledJourney;
use Acadly\Kernel\Blueprint\Compiled\CompiledProfile;
use Acadly\Kernel\Blueprint\Journey\JourneyLinter;
use Acadly\Kernel\Blueprint\Profile\ProfileBlueprint;
use Acadly\Kernel\Blueprint\Profile\ProfileLinter;
use InvalidArgumentException;
use RuntimeException;

/**
 * Compiles every declaration in the build, journeys and profiles, and checks
 * it (`blueprint:compile`, `blueprint:lint`, `acadly:lint`).
 *
 * A problem is reported, never fixed silently: a released version whose
 * declaration changed, two declarations of one ref, a profile that extends
 * one that is not declared, a compiled file with no declaration, and
 * everything the linters find.
 */
final class BlueprintCompiler
{
    /** @var list<CompiledJourney> */
    private array $journeys = [];

    /** @var array<string, CompiledProfile> */
    private array $profiles = [];

    /** @var list<string> */
    private array $problems = [];

    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(
        Declarations $declarations,
        JourneyLinter $journeyLinter,
        private readonly Lock $lock,
        ?ProfileLinter $profileLinter = null,
    ) {
        $this->problems = $declarations->problems();

        foreach ($declarations->journeys() as $declaration) {
            if (! $this->first((string) $declaration->ref)) {
                continue;
            }

            try {
                $journey = $declaration->compile();
            } catch (InvalidArgumentException $e) {
                $this->problems[] = $e->getMessage();

                continue;
            }

            $this->checkLock($journey);
            $this->problems = [...$this->problems, ...$journeyLinter->problems($journey)];
            $this->journeys[] = $journey;
        }

        $this->compileProfiles($declarations->profiles(), $profileLinter ?? new ProfileLinter);

        foreach (array_keys($lock->all()) as $released) {
            if (! isset($this->seen[$released])) {
                $this->problems[] = "{$released}: the version is released, and its declaration is missing.";
            }
        }
    }

    /** @return list<CompiledBlueprint> every compiled version, journeys first */
    public function compiled(): array
    {
        return [...$this->journeys, ...array_values($this->profiles)];
    }

    /** @return list<CompiledJourney> */
    public function journeys(): array
    {
        return $this->journeys;
    }

    /** @return list<CompiledProfile> */
    public function profiles(): array
    {
        return array_values($this->profiles);
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

        foreach ($this->compiled() as $blueprint) {
            $ref = (string) $blueprint->reference();
            $file = $folder.'/'.$ref.'.json';
            $expected[basename($file)] = true;

            if (! is_file($file)) {
                $problems[] = "{$ref}: the compiled file is missing. Run blueprint:compile.";
            } elseif (str_replace("\r\n", "\n", (string) file_get_contents($file)) !== $blueprint->json()) {
                $problems[] = "{$ref}: the compiled file is out of date. Run blueprint:compile.";
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

        foreach ($this->compiled() as $blueprint) {
            $file = $folder.'/'.$blueprint->reference().'.json';

            if (! is_file($file) || str_replace("\r\n", "\n", (string) file_get_contents($file)) !== $blueprint->json()) {
                file_put_contents($file, $blueprint->json());
                $written[] = (string) $blueprint->reference();
            }
        }

        return $written;
    }

    public function lock(): Lock
    {
        return $this->lock;
    }

    /**
     * Profiles are compiled parents first, so each child merges a compiled parent.
     *
     * @param  list<ProfileBlueprint>  $declarations
     */
    private function compileProfiles(array $declarations, ProfileLinter $linter): void
    {
        $pending = [];

        foreach ($declarations as $declaration) {
            if ($this->first((string) $declaration->ref)) {
                $pending[(string) $declaration->ref] = $declaration;
            }
        }

        while ($pending !== []) {
            $progress = false;

            foreach ($pending as $ref => $declaration) {
                $parentRef = $declaration->extendsRef();
                $parent = $parentRef !== null ? ($this->profiles[(string) $parentRef] ?? null) : null;

                if ($parentRef !== null && $parent === null && isset($pending[(string) $parentRef])) {
                    continue;
                }

                unset($pending[$ref]);
                $progress = true;

                try {
                    $profile = $declaration->compile($parent);
                } catch (InvalidArgumentException $e) {
                    $this->problems[] = $e->getMessage();

                    continue;
                }

                if ($parent !== null && ! $parent->abstract) {
                    $this->problems[] = "{$ref}: a profile extends only an abstract profile.";
                }

                $this->checkLock($profile);
                $this->problems = [...$this->problems, ...$linter->problems($profile, $declaration->termEnums())];
                $this->profiles[$ref] = $profile;
            }

            if (! $progress) {
                foreach (array_keys($pending) as $ref) {
                    $this->problems[] = "{$ref}: the profiles extend each other in a circle.";
                }

                break;
            }
        }
    }

    private function first(string $ref): bool
    {
        if (isset($this->seen[$ref])) {
            $this->problems[] = "{$ref}: the version is declared twice.";

            return false;
        }

        $this->seen[$ref] = true;

        return true;
    }

    private function checkLock(CompiledBlueprint $blueprint): void
    {
        $locked = $this->lock->hashOf($blueprint->reference());

        if ($locked !== null && ! hash_equals($locked, $blueprint->hash())) {
            $this->problems[] = "{$blueprint->reference()}: the version is released and its declaration has changed. Make a new version.";
        }
    }
}
