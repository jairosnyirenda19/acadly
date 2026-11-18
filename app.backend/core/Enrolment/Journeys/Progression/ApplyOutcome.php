<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Journeys\Progression;

use Acadly\Core\Enrolment\Domain\ProgressionDecision;
use Acadly\Kernel\Blueprint\Journey\Boundary;
use Acadly\Kernel\Blueprint\Journey\Effect;
use Acadly\Kernel\Blueprint\Journey\EffectArgs;
use Acadly\Kernel\Blueprint\Journey\EffectResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Internal: the outcome takes effect on the first day of the next school
 * year (09 §4.3). The placement of this year ends the day before; a learner
 * who goes on opens a placement in the new class with its entry reason; a
 * learner who completes or leaves ends the enrolment.
 */
final class ApplyOutcome implements Effect
{
    private const array ENTRY = ['promoted' => 'promotion', 'repeated' => 'repeat', 'progressed' => 'division_progression'];

    public function __construct(private readonly Clock $clock) {}

    public function boundary(): Boundary
    {
        return Boundary::Internal;
    }

    public function apply(JourneyContext $context, ?EffectArgs $args, string $idempotencyKey): EffectResult
    {
        $decision = $context->subject;

        if (! $decision instanceof ProgressionDecision) {
            throw new LogicException('An outcome needs a year-end decision.');
        }

        $year = DB::table('progression_runs as r')->join('academic_years as y', 'y.id', '=', 'r.to_year_id')->where('r.id', $decision->run_id)
            ->first(['y.id', 'y.starts_on']) ?? throw new LogicException('The run has no next year.');
        $starts = substr(Row::text($year, 'starts_on'), 0, 10);
        $before = CarbonImmutable::parse($starts)->subDay()->toDateString();
        $by = $context->actor?->id;

        DB::table('placements')->where('id', $decision->from_placement_id)->whereNull('ends_on')
            ->update(['ends_on' => $before, 'exit_reason' => $decision->outcome, 'closed_by' => $by]);

        $entry = self::ENTRY[$decision->outcome] ?? null;

        if ($entry === null || $decision->to_class_id === null) {
            DB::table('enrolments')->where('id', $decision->enrolment_id)->where('status', 'active')
                ->update(['status' => $decision->outcome, 'ended_on' => $before, 'end_reason' => $decision->outcome, 'updated_at' => $this->clock->now()]);

            return EffectResult::produced($decision->id);
        }

        $class = DB::table('academic_classes')->where('id', $decision->to_class_id)->first(['division_id', 'campus_id', 'level_code'])
            ?? throw new LogicException('The class of the next year is gone.');
        $id = Ids::next();
        DB::table('placements')->insert(['id' => $id, 'enrolment_id' => $decision->enrolment_id, 'learner_id' => $decision->learner_id,
            'year_id' => Row::text($year, 'id'), 'division_id' => Row::text($class, 'division_id'), 'campus_id' => Row::text($class, 'campus_id'),
            'level_code' => Row::text($class, 'level_code'), 'class_id' => $decision->to_class_id, 'starts_on' => $starts, 'entry_reason' => $entry,
            'reason' => $decision->reason, 'opened_by' => $by, 'created_at' => $this->clock->now()]);

        return EffectResult::produced($id);
    }
}
