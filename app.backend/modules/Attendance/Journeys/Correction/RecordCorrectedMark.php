<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Journeys\Correction;

use Acadly\Kernel\Blueprint\Journey\Boundary;
use Acadly\Kernel\Blueprint\Journey\Effect;
use Acadly\Kernel\Blueprint\Journey\EffectArgs;
use Acadly\Kernel\Blueprint\Journey\EffectResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\Domain\Correction;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Internal: adds the corrected mark, pointing at its correction. The first mark stays. */
final class RecordCorrectedMark implements Effect
{
    public function __construct(private readonly Clock $clock) {}

    public function boundary(): Boundary
    {
        return Boundary::Internal;
    }

    public function apply(JourneyContext $context, ?EffectArgs $args, string $idempotencyKey): EffectResult
    {
        $correction = $context->subject;

        if (! $correction instanceof Correction) {
            throw new LogicException('A corrected mark needs a register correction.');
        }

        $id = Ids::next();
        $now = $this->clock->now();
        DB::table('attendance_marks')->insert([
            'id' => $id, 'register_id' => $correction->register_id, 'learner_id' => $correction->learner_id,
            'status' => $correction->to_status, 'note' => $correction->reason, 'method' => 'correction',
            'captured_by' => $correction->requested_by, 'captured_at' => $now, 'received_at' => $now, 'correction_id' => $correction->id,
        ]);

        return EffectResult::produced($id);
    }
}
