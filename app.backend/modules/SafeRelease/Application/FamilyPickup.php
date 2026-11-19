<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Application;

use Acadly\Core\Enrolment\Contracts\FamilyLine;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\SafeRelease\Domain\Pickup;
use Acadly\Modules\SafeRelease\Journeys\Pickup\State;

/**
 * Today's pick-up on a child's card in the parent portal (D-094): who
 * collected the child and when, or that someone not on the list was refused.
 * The line is always there while the module is on, so the portal offers
 * the pick-up page.
 */
final class FamilyPickup implements FamilyLine
{
    public function __construct(
        private readonly Clock $clock,
        private readonly ModuleActivation $activation,
    ) {}

    public function line(string $learnerId, ?string $classId): ?array
    {
        if ($classId === null || ! $this->activation->isActive('saferelease', ScopeRef::of(ScopeType::ClassGroup, $classId))) {
            return null;
        }

        $pickup = Pickup::query()->where('learner_id', $learnerId)->where('day', $this->clock->today())
            ->whereIn('state', [State::Released->value, State::Disputed->value])->orderByDesc('created_at')->first();

        if (! $pickup instanceof Pickup) {
            return ['key' => 'pickup', 'word' => 'not_collected', 'label' => null, 'tone' => 'plain'];
        }

        if ($pickup->state === State::Disputed->value) {
            return ['key' => 'pickup', 'word' => 'refused', 'label' => $pickup->created_at->format('H:i'), 'tone' => 'wrong'];
        }

        $at = ($pickup->released_at ?? $pickup->created_at)->format('H:i');

        return ['key' => 'pickup', 'word' => 'collected', 'label' => $at.' · '.$pickup->collector_name, 'tone' => 'done'];
    }
}
