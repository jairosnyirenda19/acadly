<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Domain;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Blueprint\Journey\JourneySubject;
use Acadly\Kernel\Blueprint\Journey\TravelsJourney;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One piece of assessed work for one class and one subject. It travels
 * `assessment.marks`; its state changes only through the journey engine.
 *
 * @property string $id
 * @property string $class_id
 * @property string $subject_id
 * @property string $period_id
 * @property string $kind
 * @property string $title
 * @property string $held_on
 * @property int $out_of
 * @property bool $counts
 * @property string|null $part_key
 * @property string $created_by
 * @property string $state
 * @property string $journey_ref
 * @property Carbon|null $created_at
 */
final class Assessment extends Model implements JourneySubject
{
    use HasUuids, TravelsJourney;

    protected $table = 'assessments';

    protected $fillable = ['class_id', 'subject_id', 'period_id', 'kind', 'title', 'held_on', 'out_of', 'counts', 'part_key', 'created_by', 'state', 'journey_ref'];

    protected function casts(): array
    {
        return ['out_of' => 'integer', 'counts' => 'boolean'];
    }

    public function heldOn(): string
    {
        $value = $this->getAttributes()['held_on'] ?? null;

        return is_string($value) ? substr($value, 0, 10) : '';
    }

    public function journeyScope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $this->class_id);
    }

    public function auditType(): string
    {
        return 'assessment';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    public function auditFields(): array
    {
        return ['title' => FieldClass::Plain, 'kind' => FieldClass::Plain, 'held_on' => FieldClass::Plain, 'out_of' => FieldClass::Plain, 'counts' => FieldClass::Plain];
    }
}
