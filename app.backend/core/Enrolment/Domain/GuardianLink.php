<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Domain;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Blueprint\Journey\HeldRecord;
use Acadly\Kernel\Blueprint\Journey\JourneySubject;
use Acadly\Kernel\Blueprint\Journey\TravelsJourney;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One parent's link to one learner (03 §4, D-082). It travels
 * `guardian.link`; its state changes only through the journey engine. The
 * parent confirms it (a step taken by the holder); the office suspends,
 * reinstates or ends it.
 *
 * @property string $id
 * @property string $guardian_id
 * @property string $learner_id
 * @property string $contact_id
 * @property string $held_by
 * @property string $state
 * @property string $journey_ref
 */
final class GuardianLink extends Model implements HeldRecord, JourneySubject
{
    use HasUuids, TravelsJourney;

    protected $table = 'learner_guardian_relationships';

    protected $fillable = ['guardian_id', 'learner_id', 'contact_id', 'held_by', 'state', 'journey_ref'];

    public function heldBy(): string
    {
        return $this->held_by;
    }

    /** Where the learner is placed now (D-099): the office acts on a link only where it may see the learner. */
    public function journeyScope(): ScopeRef
    {
        $class = DB::table('placements')->where('learner_id', $this->learner_id)->whereNull('ends_on')->orderByDesc('starts_on')->value('class_id');

        return is_string($class) ? ScopeRef::of(ScopeType::ClassGroup, $class) : ScopeRef::institution();
    }

    public function auditType(): string
    {
        return 'guardian_link';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return 'Parent link';
    }

    public function auditFields(): array
    {
        return ['state' => FieldClass::Plain];
    }
}
