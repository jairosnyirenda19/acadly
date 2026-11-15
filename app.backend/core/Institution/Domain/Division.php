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
 * A level the school runs, pinned to a Profile Blueprint (D-007).
 *
 * @property string $id
 * @property string $name
 * @property string $profile_ref
 * @property int $position
 * @property CarbonImmutable|null $ended_at
 */
final class Division extends Model implements Auditable
{
    use HasUuids;

    protected $table = 'institution_divisions';

    protected $guarded = [];

    protected $casts = ['ended_at' => 'immutable_datetime', 'position' => 'integer'];

    /**
     * @param  Builder<Division>  $query
     * @return Builder<Division>
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->orderBy('position');
    }

    public function auditType(): string
    {
        return 'division';
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
        return ['name' => FieldClass::Plain, 'profile_ref' => FieldClass::Plain];
    }
}
