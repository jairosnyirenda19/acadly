<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Application\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Support\Application\Signals;
use Acadly\Modules\Support\Application\SupportReach;
use Acadly\Modules\Support\Contracts\SupportAudit;
use Acadly\Modules\Support\Contracts\SupportPermission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The school's support rules, and signals set aside with a reason (D-093). */
final class RuleActions
{
    public function __construct(
        private readonly Access $access,
        private readonly Signals $signals,
        private readonly SupportReach $reach,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @param array<string, int> $values by rule key; plan.review_days and plan.tell_family too */
    public function save(User $by, array $values): void
    {
        $this->access->check($by, SupportPermission::Manage);
        $known = [...array_column($this->signals->rules(), 'key'), 'plan.review_days', 'plan.tell_family'];

        foreach ($values as $key => $value) {
            if (! in_array($key, $known, true) || $value < 0 || $value > 1000) {
                throw ValidationException::withMessages(['rules' => ['Give each rule a number from 0 to 1000. 0 turns a rule off.']]);
            }
        }

        DB::transaction(function () use ($by, $values): void {
            foreach ($values as $key => $value) {
                DB::table('support_rules')->updateOrInsert(['key' => $key], ['value' => $key === 'plan.tell_family' ? ($value > 0 ? 1 : 0) : $value, 'set_by' => $by->id, 'updated_at' => $this->clock->now()]);
            }

            $this->audit->record(SupportAudit::RulesSaved, actor: $this->audit->actorFor($by), meta: ['rules' => count($values)]);
        });
    }

    /** Keeps a known learner off the list until a day, unless more signals come. */
    public function setAside(User $by, string $learnerId, string $until, string $reason): void
    {
        $at = $this->reach->learner($by, $learnerId, SupportPermission::Lead);
        $reason = trim($reason);
        $today = $this->clock->today();

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Say why it is set aside.']]);
        }
        if ($until <= $today || $until > CarbonImmutable::parse($today)->addDays(120)->toDateString()) {
            throw ValidationException::withMessages(['until' => ['Give a day within the next 120 days.']]);
        }

        $count = count($this->signals->of([$learnerId])[$learnerId] ?? []);

        DB::transaction(function () use ($by, $learnerId, $until, $reason, $count, $at): void {
            DB::table('support_set_asides')->insert(['id' => Ids::next(), 'learner_id' => $learnerId, 'until' => $until, 'reason' => mb_substr($reason, 0, 1000),
                'signals' => $count, 'set_by' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(SupportAudit::SetAside, actor: $this->audit->actorFor($by), at: $at, reason: $reason, meta: ['learner' => $learnerId, 'until' => $until]);
        });
    }
}
