<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Blueprint\Journey\JourneyContext;

/** A topic a journey outcome can send: `->notify(ErrandApproved::class)`. */
interface JourneyTopic extends Topic
{
    public static function fromJourney(JourneyContext $context): static;
}
