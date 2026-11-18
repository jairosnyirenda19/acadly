<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Domain;

use Acadly\Core\Enrolment\Journeys\Progression\State;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Blueprint\Journey\JourneySubject;
use Acadly\Kernel\Blueprint\Journey\TravelsJourney;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One learner's outcome at the year-end run (09 §4, D-088): promoted,
 * repeated, progressed to the next level group, completed or left. It
 * travels `enrolment.progression`; its state changes only through the
 * journey engine. The receiving level group's office confirms an intake.
 *
 * @property string $id
 * @property string $run_id
 * @property string $learner_id
 * @property string $enrolment_id
 * @property string $from_placement_id
 * @property string $from_class_id
 * @property string $from_division_id
 * @property string $campus_id
 * @property string $from_level
 * @property string $outcome
 * @property string|null $to_class_id
 * @property string|null $to_division_id
 * @property string|null $reason
 * @property string|null $changed_by
 * @property string $state
 * @property string $journey_ref
 */
final class ProgressionDecision extends Model implements JourneySubject
{
    use HasUuids, TravelsJourney;

    protected $table = 'progression_decisions';

    protected $fillable = ['run_id', 'learner_id', 'enrolment_id', 'from_placement_id', 'from_class_id', 'from_division_id', 'campus_id', 'from_level',
        'outcome', 'to_class_id', 'to_division_id', 'reason', 'changed_by', 'state', 'journey_ref'];

    /** The level group whose office acts now: the receiving one for an intake. */
    public function journeyScope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::Division, $this->state === State::AwaitingIntake->value && $this->to_division_id !== null ? $this->to_division_id : $this->from_division_id);
    }

    public function auditType(): string
    {
        return 'progression_decision';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return 'Year-end outcome';
    }

    public function auditFields(): array
    {
        return ['state' => FieldClass::Plain, 'outcome' => FieldClass::Plain, 'to_class_id' => FieldClass::Plain, 'reason' => FieldClass::Personal];
    }
}
