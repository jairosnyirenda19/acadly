<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

/**
 * Consequence: what follows from an outcome. An effect is idempotent. The
 * engine gives it a key that is the same for every retry of the same step,
 * and records each application under that key, so it is applied once.
 */
interface Effect
{
    public function boundary(): Boundary;

    public function apply(JourneyContext $context, ?EffectArgs $args, string $idempotencyKey): EffectResult;
}
