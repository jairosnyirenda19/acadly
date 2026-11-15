<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy\Journeys\Errand;

use Acadly\Kernel\Blueprint\Journey\Boundary;
use Acadly\Kernel\Blueprint\Journey\Effect;
use Acadly\Kernel\Blueprint\Journey\EffectArgs;
use Acadly\Kernel\Blueprint\Journey\EffectResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;

/** After commit: stands for a message to the outside world. */
final class TellRequester implements Effect
{
    /** @var list<array{key: string, decision: ?string}> */
    public static array $calls = [];

    public function boundary(): Boundary
    {
        return Boundary::AfterCommit;
    }

    public function apply(JourneyContext $context, ?EffectArgs $args, string $idempotencyKey): EffectResult
    {
        self::$calls[] = ['key' => $idempotencyKey, 'decision' => $context->producedBy(RecordDecision::class)];

        return new EffectResult(meta: ['told' => true]);
    }
}
