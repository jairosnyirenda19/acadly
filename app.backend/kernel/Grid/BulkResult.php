<?php

declare(strict_types=1);

namespace Acadly\Kernel\Grid;

/** What a bulk action did: how many rows it changed, and each row it did not, with why. */
final class BulkResult
{
    public int $done = 0;

    /** @var list<array{id: string, name: string, reason: string}> */
    public array $refused = [];

    public function did(): void
    {
        $this->done++;
    }

    public function refuse(string $id, string $name, string $reason): void
    {
        $this->refused[] = ['id' => $id, 'name' => $name, 'reason' => $reason];
    }

    /** @return array{done: int, refused: list<array{id: string, name: string, reason: string}>} */
    public function toArray(): array
    {
        return ['done' => $this->done, 'refused' => $this->refused];
    }
}
