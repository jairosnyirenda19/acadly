<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

/**
 * Admissibility: can this happen at all? A gate states a fact about the
 * world, never a permission. It is pure and cheap, and when it blocks it
 * says why and what would unblock it.
 */
interface Gate
{
    public function check(JourneyContext $context, ?GateArgs $args): GateResult;
}
