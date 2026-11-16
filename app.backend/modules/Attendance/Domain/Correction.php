<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Domain;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Blueprint\Journey\JourneySubject;
use Acadly\Kernel\Blueprint\Journey\TravelsJourney;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A request to change one learner's mark in a submitted register. It travels
 * `attendance.correction`; its state changes only through the journey engine.
 *
 * @property string $id
 * @property string $register_id
 * @property string $class_id
 * @property string $learner_id
 * @property string $from_status
 * @property string $to_status
 * @property string $reason
 * @property string $requested_by
 * @property string $state
 * @property string $journey_ref
 * @property Carbon|null $created_at
 */
final class Correction extends Model implements JourneySubject
{
    use HasUuids, TravelsJourney;

    protected $table = 'attendance_corrections';

    protected $fillable = ['register_id', 'class_id', 'learner_id', 'from_status', 'to_status', 'reason', 'requested_by', 'state', 'journey_ref'];

    public function journeyScope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $this->class_id);
    }

    public function auditType(): string
    {
        return 'attendance_correction';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return 'Register correction';
    }

    public function auditFields(): array
    {
        return ['from_status' => FieldClass::Plain, 'to_status' => FieldClass::Plain, 'reason' => FieldClass::Personal];
    }
}
