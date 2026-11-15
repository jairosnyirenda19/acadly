<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Audit\FieldClass;
use Acadly\Kernel\Blueprint\Journey\JourneySubject;
use Acadly\Kernel\Blueprint\Journey\TravelsJourney;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $title
 * @property string $state
 * @property string $journey_ref
 * @property string|null $requested_by
 * @property string|null $requester_phone
 */
final class ToyErrand extends Model implements JourneySubject
{
    use HasUuids, TravelsJourney;

    protected $table = 'toy_errands';

    protected $fillable = ['title', 'state', 'journey_ref', 'requested_by', 'requester_phone'];

    public function journeyScope(): ScopeRef
    {
        return ScopeRef::institution();
    }

    public function auditType(): string
    {
        return 'toy_errand';
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
        return ['title' => FieldClass::Plain];
    }
}
