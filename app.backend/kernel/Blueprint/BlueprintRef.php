<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint;

use InvalidArgumentException;

/**
 * The identity of one blueprint version: `KEY@VERSION`
 * (docs/architecture/02-blueprints.md §1).
 *
 * A journey key is lower case with dots (`errand.request`); a profile key is
 * upper case (`SCHOOL_BASE`). A version is a year and a release number
 * (`2027.1`). Instances store the ref and are always loaded by it.
 */
final readonly class BlueprintRef
{
    private const string KEY = '/^([a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+|[A-Z][A-Z0-9_]*)$/';

    private const string VERSION = '/^[0-9]{4}\.[0-9]{1,3}$/';

    private function __construct(
        public string $key,
        public string $version,
    ) {}

    public static function parse(string $ref): self
    {
        $parts = explode('@', $ref);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException("[{$ref}] is not a blueprint reference of the form KEY@VERSION.");
        }

        return self::of($parts[0], $parts[1]);
    }

    public static function of(string $key, string $version): self
    {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException("[{$key}] is not a valid blueprint key.");
        }

        if (preg_match(self::VERSION, $version) !== 1) {
            throw new InvalidArgumentException("[{$version}] is not a valid blueprint version. Use YYYY.N.");
        }

        return new self($key, $version);
    }

    public function kind(): BlueprintKind
    {
        return strtoupper($this->key) === $this->key ? BlueprintKind::Profile : BlueprintKind::Journey;
    }

    public function owner(): string
    {
        return explode('.', $this->key)[0];
    }

    /** Orders versions: 2027.10 comes after 2027.9. */
    public function compareVersion(self $other): int
    {
        [$year, $number] = array_map(intval(...), explode('.', $this->version));
        [$otherYear, $otherNumber] = array_map(intval(...), explode('.', $other->version));

        return [$year, $number] <=> [$otherYear, $otherNumber];
    }

    public function equals(self $other): bool
    {
        return $this->key === $other->key && $this->version === $other->version;
    }

    public function __toString(): string
    {
        return $this->key.'@'.$this->version;
    }
}
