<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

use Acadly\Kernel\Blueprint\BlueprintRef;
use Illuminate\Database\Eloquent\Model;

/**
 * The storage half of `JourneySubject` for an Eloquent model with the
 * columns `journey_ref` and `state`. A model with another state column
 * overrides `journeyStateColumn()`.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements JourneySubject
 */
trait TravelsJourney
{
    public function journeyRef(): BlueprintRef
    {
        $ref = $this->getAttribute('journey_ref');

        return BlueprintRef::parse(is_string($ref) ? $ref : '');
    }

    public function journeyState(): string
    {
        $state = $this->getAttribute($this->journeyStateColumn());

        return is_string($state) ? $state : '';
    }

    public function journeyTable(): string
    {
        return $this->getTable();
    }

    public function journeyStateColumn(): string
    {
        return 'state';
    }

    public function journeyStateCommitted(string $state): void
    {
        $column = $this->journeyStateColumn();

        $this->setAttribute($column, $state);
        $this->syncOriginalAttribute($column);
    }

    public static function findJourneySubject(string $id): ?static
    {
        $found = static::query()->find($id);

        return $found instanceof static ? $found : null;
    }
}
