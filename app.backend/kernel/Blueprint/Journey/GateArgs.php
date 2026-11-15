<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

/** Typed arguments for a gate, written into the compiled blueprint. */
interface GateArgs
{
    /** @return array<string, scalar|null> */
    public function toArray(): array;

    /** @param  array<string, scalar|null>  $values */
    public static function fromArray(array $values): static;
}
