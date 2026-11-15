<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role held by an account at a scope, for a period. Ending an assignment
 * keeps the row, with who ended it and why.
 *
 * @property string $id
 * @property string $user_id
 * @property string $role_id
 * @property ScopeType $scope_type
 * @property string|null $scope_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property string|null $granted_by
 * @property string|null $reason
 * @property string|null $ended_by
 * @property string|null $end_reason
 * @property-read Role $role
 */
final class RoleAssignment extends Model
{
    use HasUuids;

    protected $table = 'access_role_assignments';

    protected $fillable = [
        'user_id', 'role_id', 'scope_type', 'scope_id', 'starts_at', 'ends_at',
        'granted_by', 'reason', 'ended_by', 'end_reason',
    ];

    public function scope(): ScopeRef
    {
        return ScopeRef::of($this->scope_type, $this->scope_id);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Assignments in force at a moment.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveAt(Builder $query, CarbonImmutable $at): Builder
    {
        return $query
            ->where('starts_at', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    protected function casts(): array
    {
        return [
            'scope_type' => ScopeType::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }
}
