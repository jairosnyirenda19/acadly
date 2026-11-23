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
 * The result sheet of one course at one sitting (D-105). It travels
 * `results.sheet`; its state changes only through the journey engine.
 *
 * @property string $id
 * @property string $division_id
 * @property string $period_id
 * @property string $offering_id
 * @property string $sitting
 * @property string $state
 * @property string $journey_ref
 * @property string|null $outcome
 * @property Carbon|null $exam_on
 * @property Carbon|null $released_at
 * @property string|null $release_id
 * @property string $created_by
 * @property Carbon $created_at
 */
final class Sheet extends Model implements Auditable, JourneySubject
{
    use HasUuids, TravelsJourney;

    protected $table = 'results_sheets';

    protected $fillable = ['division_id', 'period_id', 'offering_id', 'sitting', 'state', 'journey_ref', 'outcome', 'exam_on', 'released_at', 'release_id', 'created_by'];

    protected function casts(): array
    {
        return ['exam_on' => 'date', 'released_at' => 'datetime'];
    }

    public function journeyScope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::Division, $this->division_id);
    }

    public function auditType(): string
    {
        return 'results_sheet';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return 'Result sheet';
    }

    public function auditFields(): array
    {
        return ['state' => FieldClass::Plain, 'outcome' => FieldClass::Plain, 'exam_on' => FieldClass::Plain, 'released_at' => FieldClass::Plain];
    }
}
