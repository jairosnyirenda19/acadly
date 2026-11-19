<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Domain;

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
 * One child being collected, or refused, on a day (D-094). It travels
 * `saferelease.pickup`; its state changes only through the journey engine.
 *
 * @property string $id
 * @property string $learner_id
 * @property string $class_id
 * @property Carbon $day
 * @property string $state
 * @property string $journey_ref
 * @property string $collector_kind
 * @property string|null $collector_ref
 * @property string $collector_name
 * @property string|null $collector_relationship
 * @property bool $code_checked
 * @property string|null $note
 * @property string $created_by
 * @property Carbon|null $released_at
 * @property Carbon $created_at
 */
final class Pickup extends Model implements Auditable, JourneySubject
{
    use HasUuids, TravelsJourney;

    public const string CONTACT = 'contact';

    public const string COLLECTOR = 'collector';

    public const string UNKNOWN = 'unknown';

    protected $table = 'pickups';

    protected $fillable = ['learner_id', 'class_id', 'day', 'state', 'journey_ref', 'collector_kind', 'collector_ref', 'collector_name', 'collector_relationship',
        'code_checked', 'note', 'created_by', 'released_at'];

    protected function casts(): array
    {
        return ['day' => 'date', 'code_checked' => 'boolean', 'released_at' => 'datetime'];
    }

    public function journeyScope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $this->class_id);
    }

    public function auditType(): string
    {
        return 'pickup';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return 'Pick-up';
    }

    public function auditFields(): array
    {
        return ['state' => FieldClass::Plain, 'collector_name' => FieldClass::Personal, 'note' => FieldClass::Personal];
    }
}
