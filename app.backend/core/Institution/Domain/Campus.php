<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Domain;

use Acadly\Kernel\Audit\Auditable;
use Acadly\Kernel\Audit\FieldClass;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A site of the school.
 *
 * @property string $id
 * @property string $name
 * @property string $place
 * @property string|null $address
 * @property int $position
 * @property CarbonImmutable|null $ended_at
 */
final class Campus extends Model implements Auditable
{
    use HasUuids;

    protected $table = 'institution_campuses';

    protected $guarded = [];

    protected $casts = ['ended_at' => 'immutable_datetime', 'position' => 'integer'];

    /**
     * @param  Builder<Campus>  $query
     * @return Builder<Campus>
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->orderBy('position');
    }

    public function auditType(): string
    {
        return 'campus';
    }

    public function auditId(): string
    {
        return $this->id;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditFields(): array
    {
        return ['name' => FieldClass::Plain, 'place' => FieldClass::Plain, 'address' => FieldClass::Plain];
    }
}
