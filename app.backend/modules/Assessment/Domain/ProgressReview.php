<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Domain;

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
 * One child's progress review for one term (D-094). It travels
 * `assessment.progress_review`; its state changes only through the journey
 * engine. The notes change only while it is a draft.
 *
 * @property string $id
 * @property string $learner_id
 * @property string $class_id
 * @property string $division_id
 * @property string $period_id
 * @property string $state
 * @property string $journey_ref
 * @property string|null $family_note
 * @property string|null $home_note
 * @property string $drafted_by
 * @property Carbon|null $shared_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ProgressReview extends Model implements Auditable, JourneySubject
{
    use HasUuids, TravelsJourney;

    protected $table = 'progress_reviews';

    protected $fillable = ['learner_id', 'class_id', 'division_id', 'period_id', 'state', 'journey_ref', 'family_note', 'home_note', 'drafted_by', 'shared_at'];

    protected function casts(): array
    {
        return ['shared_at' => 'datetime'];
    }

    public function journeyScope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $this->class_id);
    }

    public function auditType(): string
    {
        return 'progress_review';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return 'Progress review';
    }

    public function auditFields(): array
    {
        return ['state' => FieldClass::Plain, 'family_note' => FieldClass::Personal, 'home_note' => FieldClass::Personal];
    }
}
