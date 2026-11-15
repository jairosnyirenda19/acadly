<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Compiled;

use Acadly\Kernel\Blueprint\BlueprintRef;

/** A journey or a profile in its compiled form: what is hashed, locked, stored and loaded. */
interface CompiledBlueprint
{
    public function reference(): BlueprintRef;

    public function hash(): string;

    public function json(): string;
}
