<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Domain;

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
 * A support plan for one learner (D-093). It travels `support.plan`; its
 * state changes only through the journey engine.
 *
 * @property string $id
 * @property string $learner_id
 * @property string $class_id
 * @property string $title
 * @property string $concern
 * @property string|null $signals
 * @property string $lead_by
 * @property bool $confidential
 * @property bool $tell_family
 * @property int $review_days
 * @property Carbon $next_review_on
 * @property string $state
 * @property string $journey_ref
 * @property string|null $outcome
 * @property string|null $closing_note
 * @property string $opened_by
 * @property Carbon $created_at
 */
final class SupportPlan extends Model implements Auditable, JourneySubject
{
    use HasUuids, TravelsJourney;

    protected $table = 'support_plans';

    protected $fillable = ['learner_id', 'class_id', 'title', 'concern', 'signals', 'lead_by', 'confidential', 'tell_family', 'review_days', 'next_review_on',
        'state', 'journey_ref', 'outcome', 'closing_note', 'opened_by'];

    protected function casts(): array
    {
        return ['confidential' => 'boolean', 'tell_family' => 'boolean', 'review_days' => 'integer', 'next_review_on' => 'date'];
    }

    public function journeyScope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $this->class_id);
    }

    public function auditType(): string
    {
        return 'support_plan';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return 'Support plan';
    }

    public function auditFields(): array
    {
        return ['state' => FieldClass::Plain, 'outcome' => FieldClass::Plain, 'lead_by' => FieldClass::Plain, 'closing_note' => FieldClass::Personal];
    }
}
