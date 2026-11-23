<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Domain;

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
 * The end-of-year progression list of one programme and year (D-106). It
 * travels `results.progression`; its state changes only through the journey engine.
 *
 * @property string $id
 * @property string $division_id
 * @property string $period_id
 * @property string $programme_id
 * @property int $year_of_study
 * @property string $state
 * @property string $journey_ref
 * @property string|null $outcome
 * @property string $created_by
 * @property Carbon $created_at
 */
final class ProgressionList extends Model implements Auditable, JourneySubject
{
    use HasUuids, TravelsJourney;

    protected $table = 'results_progression_lists';

    protected $fillable = ['division_id', 'period_id', 'programme_id', 'year_of_study', 'state', 'journey_ref', 'outcome', 'created_by'];

    protected function casts(): array
    {
        return ['year_of_study' => 'integer'];
    }

    public function journeyScope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::Division, $this->division_id);
    }

    public function auditType(): string
    {
        return 'results_progression_list';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return 'Progression list';
    }

    public function auditFields(): array
    {
        return ['state' => FieldClass::Plain, 'outcome' => FieldClass::Plain];
    }
}
