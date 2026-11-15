<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint;

use Acadly\Kernel\Blueprint\Compiled\CompiledBlueprint;
use Acadly\Kernel\Blueprint\Compiled\CompiledJourney;
use Acadly\Kernel\Blueprint\Compiled\CompiledProfile;
use LogicException;
use Throwable;

/**
 * The compiled blueprints of this build, loaded from `blueprints/compiled`.
 * The engine asks for a journey, and a division for its profile, by the ref
 * the record is pinned to, never for "the current one". A released version
 * whose file no longer matches the lock is refused.
 */
final class Blueprints
{
    /** @var array<string, CompiledJourney> */
    private array $journeys = [];

    /** @var array<string, CompiledProfile> */
    private array $profiles = [];

    /** @param  iterable<CompiledBlueprint>  $blueprints */
    public function __construct(iterable $blueprints = [])
    {
        foreach ($blueprints as $blueprint) {
            if ($blueprint instanceof CompiledJourney) {
                $this->journeys[(string) $blueprint->ref] = $blueprint;
            } elseif ($blueprint instanceof CompiledProfile) {
                $this->profiles[(string) $blueprint->ref] = $blueprint;
            }
        }
    }

    /** Reads every compiled blueprint in a folder and checks the released ones against the lock. */
    public static function load(string $folder, Lock $lock): self
    {
        $blueprints = [];

        foreach (is_dir($folder) ? (glob($folder.'/*.json') ?: []) : [] as $path) {
            try {
                $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
                $data = is_array($data) ? $data : [];
                $blueprint = ($data['kind'] ?? null) === BlueprintKind::Profile->value
                    ? CompiledProfile::fromArray($data)
                    : CompiledJourney::fromArray($data);
            } catch (Throwable $e) {
                throw new LogicException('A compiled blueprint cannot be read: '.basename($path).'.', 0, $e);
            }

            if (basename($path) !== $blueprint->reference().'.json') {
                throw new LogicException('A compiled blueprint is stored under the wrong name: '.basename($path).'.');
            }

            $locked = $lock->hashOf($blueprint->reference());

            if ($locked !== null && ! hash_equals($locked, $blueprint->hash())) {
                throw new LogicException("The released blueprint [{$blueprint->reference()}] does not match its locked hash.");
            }

            $blueprints[] = $blueprint;
        }

        return new self($blueprints);
    }

    public function has(BlueprintRef $ref): bool
    {
        return isset($this->journeys[(string) $ref]) || isset($this->profiles[(string) $ref]);
    }

    public function journey(BlueprintRef $ref): CompiledJourney
    {
        return $this->journeys[(string) $ref] ?? throw new LogicException("The journey [{$ref}] is not in this build.");
    }

    public function profile(BlueprintRef $ref): CompiledProfile
    {
        return $this->profiles[(string) $ref] ?? throw new LogicException("The profile [{$ref}] is not in this build.");
    }

    /** @return list<CompiledJourney> */
    public function journeys(): array
    {
        return array_values($this->journeys);
    }

    /**
     * The newest version of each profile that a division can be pinned to, in key order.
     *
     * @return list<CompiledProfile>
     */
    public function pinnableProfiles(): array
    {
        $newest = [];

        foreach ($this->profiles as $profile) {
            $key = $profile->ref->key;

            if (! $profile->abstract && (! isset($newest[$key]) || $profile->ref->compareVersion($newest[$key]->ref) > 0)) {
                $newest[$key] = $profile;
            }
        }

        ksort($newest);

        return array_values($newest);
    }

    /** @return list<CompiledBlueprint> */
    public function all(): array
    {
        return [...array_values($this->journeys), ...array_values($this->profiles)];
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
