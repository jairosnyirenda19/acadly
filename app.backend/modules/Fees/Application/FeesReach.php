<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Boundary;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A learner's fees live where the learner is placed. Someone who may neither
 * see fees nor act on them there is told "not found", so a refusal never
 * confirms that a learner exists.
 */
final class FeesReach
{
    public function __construct(
        private readonly Access $access,
        private readonly EnrolmentDirectory $directory,
        private readonly Clock $clock,
    ) {}

    /** The learner's class, checked for the permission: or not found, or refused. */
    public function learner(User $who, string $learnerId, FeesPermission $permission): ScopeRef
    {
        $place = $this->directory->placeOf($learnerId);
        $at = $place === null ? null : ScopeRef::of(ScopeType::ClassGroup, $place['class_id']);

        if ($at === null || (! $this->access->allows($who, FeesPermission::View, $at) && ! $this->access->allows($who, $permission, $at))) {
            throw new NotFoundHttpException;
        }

        $this->access->check($who, $permission, $at);

        return $at;
    }

    /** @return array{class_id: string, division_id: string, campus_id: string, year_id: string, open: bool, since: string}|null */
    public function place(string $learnerId): ?array
    {
        return $this->directory->placeOf($learnerId);
    }

    public function may(User $who, FeesPermission $permission, string $learnerId): bool
    {
        $place = $this->directory->placeOf($learnerId);

        return $place !== null && $this->access->allows($who, $permission, ScopeRef::of(ScopeType::ClassGroup, $place['class_id']));
    }

    /** Whether a day is closed by an office: it takes no more payments. */
    public function dayClosed(string $day, string $office = FeeOffices::SCHOOL): bool
    {
        return DB::table('fee_days')->where('day', $day)->where('office_id', $office)->exists();
    }

    /**
     * The classes, among those with learners now, where the viewer may see or
     * collect fees in the place worked in (D-099); null when no place bounds the request.
     *
     * @return list<string>|null
     */
    public function classesInPlace(User $who): ?array
    {
        if (app(Boundary::class)->offerings() === null) {
            return null;
        }

        $out = [];
        foreach (DB::table('placements')->whereNull('ends_on')->distinct()->pluck('class_id') as $class) {
            if (! is_string($class)) {
                continue;
            }
            $at = ScopeRef::of(ScopeType::ClassGroup, $class);
            if ($this->access->allows($who, FeesPermission::View, $at) || $this->access->allows($who, FeesPermission::Collect, $at)) {
                $out[] = $class;
            }
        }

        return $out;
    }

    /** Keeps a query to the learners of the place worked in, by a learner id column. */
    public function keepLearners(Builder $query, string $learnerColumn, User $who): Builder
    {
        $classes = $this->classesInPlace($who);

        return $classes === null ? $query : $query->whereIn($learnerColumn, DB::table('placements')->whereNull('ends_on')->whereIn('class_id', $classes ?: ['00000000-0000-0000-0000-000000000000'])->select('learner_id'));
    }

    /** Keeps a payments query (alias p) to payments with a share for a learner of the place. */
    public function keepPayments(Builder $query, User $who): Builder
    {
        $classes = $this->classesInPlace($who);

        return $classes === null ? $query : $query->whereExists(fn (Builder $q): Builder => $q->from('fee_payment_shares as ks')->whereColumn('ks.payment_id', 'p.id')
            ->whereIn('ks.learner_id', DB::table('placements')->whereNull('ends_on')->whereIn('class_id', $classes ?: ['00000000-0000-0000-0000-000000000000'])->select('learner_id')));
    }

    public function today(): string
    {
        return $this->clock->today();
    }
}
