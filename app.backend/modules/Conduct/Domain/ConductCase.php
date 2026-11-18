<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Domain;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Auditable;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Blueprint\Journey\JourneySubject;
use Acadly\Kernel\Blueprint\Journey\TravelsJourney;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A serious incident (D-092). It travels `conduct.serious_incident`; its
 * state changes only through the journey engine. It lives at the class of
 * the incident's first learner.
 *
 * @property string $id
 * @property string $number
 * @property string $entry_id
 * @property string $class_id
 * @property string|null $lead_by
 * @property string $state
 * @property string $journey_ref
 * @property Carbon $created_at
 */
final class ConductCase extends Model implements Auditable, JourneySubject
{
    use HasUuids, TravelsJourney;

    protected $table = 'conduct_cases';

    protected $fillable = ['number', 'entry_id', 'class_id', 'lead_by', 'state', 'journey_ref'];

    public function journeyScope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $this->class_id);
    }

    public function auditType(): string
    {
        return 'conduct_case';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return 'Serious incident '.$this->number;
    }

    public function auditFields(): array
    {
        return ['state' => FieldClass::Plain, 'lead_by' => FieldClass::Plain];
    }
}
