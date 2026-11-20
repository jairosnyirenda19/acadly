<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Domain;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Auditable;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Blueprint\Journey\HeldRecord;
use Acadly\Kernel\Blueprint\Journey\JourneySubject;
use Acadly\Kernel\Blueprint\Journey\TravelsJourney;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One student's registration for one semester (D-098). It travels
 * `programmes.registration`; its state changes only through the journey engine.
 *
 * @property string $id
 * @property string $division_id
 * @property string $period_id
 * @property string $learner_id
 * @property string $student_id
 * @property int $year_of_study
 * @property int $semester
 * @property string $state
 * @property string $journey_ref
 * @property string|null $outcome
 * @property bool $late
 * @property int $version
 * @property string|null $fees_allowed_by
 * @property string|null $fees_allowed_reason
 * @property Carbon|null $fees_allowed_at
 * @property string $opened_by
 * @property Carbon $created_at
 */
final class Registration extends Model implements Auditable, HeldRecord, JourneySubject
{
    /** The student's own account (D-101): they may send their own registration. */
    public function heldBy(): ?string
    {
        $user = DB::table('programme_student_accounts')->where('learner_id', $this->learner_id)->whereNull('suspended_at')->value('user_id');

        return is_string($user) ? $user : null;
    }

    use HasUuids, TravelsJourney;

    protected $table = 'programme_registrations';

    protected $fillable = ['division_id', 'period_id', 'learner_id', 'student_id', 'year_of_study', 'semester', 'state', 'journey_ref', 'outcome', 'late', 'version',
        'fees_allowed_by', 'fees_allowed_reason', 'fees_allowed_at', 'opened_by'];

    protected function casts(): array
    {
        return ['late' => 'boolean', 'version' => 'integer', 'year_of_study' => 'integer', 'semester' => 'integer', 'fees_allowed_at' => 'datetime'];
    }

    public function journeyScope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::Division, $this->division_id);
    }

    public function auditType(): string
    {
        return 'programme_registration';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return 'Semester registration';
    }

    public function auditFields(): array
    {
        return ['state' => FieldClass::Plain, 'outcome' => FieldClass::Plain, 'version' => FieldClass::Plain, 'late' => FieldClass::Plain,
            'fees_allowed_by' => FieldClass::Plain, 'fees_allowed_reason' => FieldClass::Personal];
    }
}
