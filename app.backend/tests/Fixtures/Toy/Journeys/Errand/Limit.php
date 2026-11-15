<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy\Journeys\Errand;

use Acadly\Kernel\Blueprint\Journey\GateArgs;

final readonly class Limit implements GateArgs
{
    public function __construct(public int $max) {}

    public function toArray(): array
    {
        return ['max' => $this->max];
    }

    public static function fromArray(array $values): static
    {
        $max = $values['max'] ?? 0;

        return new self(is_int($max) ? $max : 0);
    }
}
