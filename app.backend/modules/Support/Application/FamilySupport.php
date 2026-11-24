<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Application;

use Acadly\Core\Enrolment\Contracts\Guardianship;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Phone;
use Acadly\Modules\Support\Domain\SupportPlan;
use Acadly\Modules\Support\Journeys\Plan\State;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Support plans in the parent portal (D-110). A confirmed parent sees the
 * open plans the school chose to show, and of each only the part written for
 * the family. A confidential plan never reaches the portal. Anything else is
 * "not found".
 */
final class FamilySupport
{
    public function __construct(
        private readonly Guardianship $guardianship,
        private readonly ModuleActivation $activation,
        private readonly SchoolProfile $school,
    ) {}

    /**
     * @return array{child: array{id: string, first_name: string, name: string}, plans: list<array{id: string, title: string, lead: string, opened_on: string, next_review_on: string, state: string, school: string, home: string}>, school_phone: string|null}
     */
    public function child(User $parent, string $learnerId): array
    {
        $child = $this->guardianship->child($parent, $learnerId);

        if ($child === null || ! $this->on($child['class_id'])) {
            throw new NotFoundHttpException;
        }

        $plans = [];

        foreach (self::shown($learnerId) as $plan) {
            $lead = DB::table('users')->where('id', $plan->lead_by)->value('name');
            $plans[] = ['id' => $plan->id, 'title' => $plan->title, 'lead' => is_string($lead) ? $lead : '', 'opened_on' => $plan->created_at->toDateString(),
                'next_review_on' => $plan->next_review_on->toDateString(), 'state' => State::from($plan->state)->label(),
                'school' => (string) $plan->family_school, 'home' => (string) $plan->family_home];
        }

        if ($plans === []) {
            throw new NotFoundHttpException;
        }

        return ['child' => ['id' => $child['id'], 'first_name' => $child['first_name'], 'name' => $child['name']], 'plans' => $plans,
            'school_phone' => Phone::forPeople($this->school->phone())];
    }

    /** @return Collection<int, SupportPlan> the open plans shown to the family, the next review first */
    public static function shown(string $learnerId): Collection
    {
        return SupportPlan::query()->where('learner_id', $learnerId)->where('state', '!=', State::Closed->value)->where('confidential', false)
            ->where('family_shown', true)->whereNotNull('family_school')->whereNotNull('family_home')->orderBy('next_review_on')->get();
    }

    private function on(?string $classId): bool
    {
        return $classId !== null && $this->activation->isActive('support', ScopeRef::of(ScopeType::ClassGroup, $classId));
    }
}
