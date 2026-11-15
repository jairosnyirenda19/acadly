<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy\Journeys\Errand;

use Acadly\Kernel\Blueprint\Journey\Boundary;
use Acadly\Kernel\Blueprint\Journey\Effect;
use Acadly\Kernel\Blueprint\Journey\EffectArgs;
use Acadly\Kernel\Blueprint\Journey\EffectResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Internal: writes the decision row inside the step's transaction. */
final class RecordDecision implements Effect
{
    public static int $applied = 0;

    public static bool $fail = false;

    public function boundary(): Boundary
    {
        return Boundary::Internal;
    }

    public function apply(JourneyContext $context, ?EffectArgs $args, string $idempotencyKey): EffectResult
    {
        if (self::$fail) {
            throw new RuntimeException('The decision could not be recorded.');
        }

        self::$applied++;
        $id = Ids::next();

        DB::table('toy_decisions')->insert([
            'id' => $id,
            'errand_id' => $context->subject->auditId(),
            'outcome' => $context->outcome ?? '',
            'reason' => $context->text('reason'),
            'created_at' => now(),
        ]);

        return EffectResult::produced($id);
    }
}
