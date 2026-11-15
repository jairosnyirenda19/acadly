<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Blueprint\Journey\Boundary;
use Acadly\Kernel\Blueprint\Journey\Effect;
use Acadly\Kernel\Blueprint\Journey\EffectArgs;
use Acadly\Kernel\Blueprint\Journey\EffectResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use LogicException;

/**
 * The journey effect behind `Outcome::notify(Topic::class)`. Internal: it
 * writes the request row in the step's transaction, so a rolled-back step
 * tells nobody. Recipients and channels are resolved after commit.
 */
final class SendTopic implements Effect
{
    public function __construct(private readonly Notifier $notifier) {}

    public function boundary(): Boundary
    {
        return Boundary::Internal;
    }

    public function apply(JourneyContext $context, ?EffectArgs $args, string $idempotencyKey): EffectResult
    {
        $topic = $args instanceof TopicArgs ? $args->topic : '';

        if (! is_subclass_of($topic, JourneyTopic::class)) {
            throw new LogicException("[{$topic}] is not a journey topic.");
        }

        $id = $this->notifier->send($topic::fromJourney($context), $context->actor);

        return $id !== null ? EffectResult::produced($id) : EffectResult::none();
    }
}
