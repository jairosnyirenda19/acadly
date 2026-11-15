<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint;

use Acadly\Kernel\Blueprint\Compiled\CompiledJourney;
use LogicException;
use Throwable;

/**
 * The compiled blueprints of this build, loaded from `blueprints/compiled`.
 * The engine asks for a journey by the ref a record is pinned to, never for
 * "the current one". A released version whose file no longer matches the
 * lock is refused.
 */
final class Blueprints
{
    /** @var array<string, CompiledJourney> */
    private array $journeys = [];

    /** @param  iterable<CompiledJourney>  $journeys */
    public function __construct(iterable $journeys = [])
    {
        foreach ($journeys as $journey) {
            $this->journeys[(string) $journey->ref] = $journey;
        }
    }

    /** Reads every compiled journey in a folder and checks the released ones against the lock. */
    public static function load(string $folder, Lock $lock): self
    {
        $journeys = [];

        foreach (is_dir($folder) ? (glob($folder.'/*.json') ?: []) : [] as $path) {
            try {
                $journey = CompiledJourney::fromJson((string) file_get_contents($path));
            } catch (Throwable $e) {
                throw new LogicException('A compiled blueprint cannot be read: '.basename($path).'.', 0, $e);
            }

            if (basename($path) !== $journey->ref.'.json') {
                throw new LogicException('A compiled blueprint is stored under the wrong name: '.basename($path).'.');
            }

            $locked = $lock->hashOf($journey->ref);

            if ($locked !== null && ! hash_equals($locked, $journey->hash())) {
                throw new LogicException("The released blueprint [{$journey->ref}] does not match its locked hash.");
            }

            $journeys[] = $journey;
        }

        return new self($journeys);
    }

    public function has(BlueprintRef $ref): bool
    {
        return isset($this->journeys[(string) $ref]);
    }

    public function journey(BlueprintRef $ref): CompiledJourney
    {
        return $this->journeys[(string) $ref] ?? throw new LogicException("The journey [{$ref}] is not in this build.");
    }

    /** @return list<CompiledJourney> */
    public function journeys(): array
    {
        return array_values($this->journeys);
    }

    /**
     * The newest version of a journey key, for pinning a new record until a
     * Profile Blueprint names the version a division uses.
     */
    public function newest(string $key): BlueprintRef
    {
        $newest = null;

        foreach ($this->journeys as $journey) {
            if ($journey->ref->key === $key && ($newest === null || $journey->ref->compareVersion($newest) > 0)) {
                $newest = $journey->ref;
            }
        }

        return $newest ?? throw new LogicException("No version of the journey [{$key}] is in this build.");
    }
}
