<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint;

use RuntimeException;

/**
 * `blueprints.lock`: the content hash of every released blueprint version
 * (02 §1 law 2). A released version never changes. The compiler and the
 * linter fail when a released hash no longer matches.
 */
final class Lock
{
    /** @var array<string, array{hash: string, released_at: string}> */
    private array $entries = [];

    public function __construct(private readonly string $path)
    {
        if (! is_file($path)) {
            return;
        }

        $data = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);

        foreach (is_array($data) ? $data : [] as $ref => $entry) {
            if (is_string($ref) && is_array($entry) && is_string($entry['hash'] ?? null) && is_string($entry['released_at'] ?? null)) {
                $this->entries[$ref] = ['hash' => $entry['hash'], 'released_at' => $entry['released_at']];
            }
        }
    }

    public function isReleased(BlueprintRef $ref): bool
    {
        return isset($this->entries[(string) $ref]);
    }

    public function hashOf(BlueprintRef $ref): ?string
    {
        return $this->entries[(string) $ref]['hash'] ?? null;
    }

    /** @return array<string, array{hash: string, released_at: string}> */
    public function all(): array
    {
        return $this->entries;
    }

    public function release(BlueprintRef $ref, string $hash, string $releasedAt): void
    {
        if ($this->isReleased($ref)) {
            throw new RuntimeException("[{$ref}] is already released.");
        }

        $this->entries[(string) $ref] = ['hash' => $hash, 'released_at' => $releasedAt];
        ksort($this->entries, SORT_STRING);

        $folder = dirname($this->path);

        if (! is_dir($folder) && ! mkdir($folder, 0775, true) && ! is_dir($folder)) {
            throw new RuntimeException('The lock folder cannot be created.');
        }

        file_put_contents($this->path, Canonical::json($this->entries));
    }
}
