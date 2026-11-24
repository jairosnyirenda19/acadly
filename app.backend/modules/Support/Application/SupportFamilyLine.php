<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Application;

use Acadly\Core\Enrolment\Contracts\FamilyLine;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Module\ModuleActivation;

/** The support plan door on a child's screen in the portal (D-110): the next review and the title, as "2027-02-20 Reading support". */
final class SupportFamilyLine implements FamilyLine
{
    public function __construct(private readonly ModuleActivation $activation) {}

    public function line(string $learnerId, ?string $classId): ?array
    {
        if ($classId === null || ! $this->activation->isActive('support', ScopeRef::of(ScopeType::ClassGroup, $classId))) {
            return null;
        }

        $plan = FamilySupport::shown($learnerId)->first();

        return $plan === null ? null : ['key' => 'plan', 'word' => 'plan', 'label' => $plan->next_review_on->toDateString().' '.$plan->title, 'tone' => 'plain'];
    }
}
