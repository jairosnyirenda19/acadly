<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Journeys\Plan;

use Acadly\Kernel\Blueprint\Journey\Gate;
use Acadly\Kernel\Blueprint\Journey\GateArgs;
use Acadly\Kernel\Blueprint\Journey\GateResult;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Modules\Support\Domain\SupportPlan;
use Illuminate\Support\Facades\DB;

/** A plan is agreed only once it has at least one goal. */
final class HasAGoal implements Gate
{
    public function check(JourneyContext $context, ?GateArgs $args): GateResult
    {
        $plan = $context->subject;

        if (! $plan instanceof SupportPlan) {
            return GateResult::blocked('This is not a support plan.', 'Open the plan from Support.');
        }

        return DB::table('support_goals')->where('plan_id', $plan->id)->exists()
            ? GateResult::ok()
            : GateResult::blocked('The plan has no goal yet.', 'Add at least one goal.');
    }
}
