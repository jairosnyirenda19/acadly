<?php

declare(strict_types=1);

namespace Acadly\Core\Setup\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Institution\Contracts\DivisionView;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Identity\AccountStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Where setup stands, step by step (the approved setup screens). A step is
 * done when what it sets is complete; staff is optional. The school can open
 * when every required step is done and an administrator holds the school.
 */
final class SetupProgress
{
    public const array STEPS = ['school', 'levels', 'year', 'classes', 'staff', 'review'];

    public function __construct(
        private readonly SchoolDirectory $school,
        private readonly AcademicCalendar $calendar,
    ) {}

    /** @return array<string, bool> step => done */
    public function steps(): array
    {
        $year = $this->calendar->currentYear();
        $divisions = $this->school->divisionViews();
        $offerings = $this->school->offerings();
        $perYear = $divisions !== [] ? $divisions[0]->profile->periodsPerYear : null;
        $taught = [];

        foreach ($offerings as $offering) {
            $taught[$offering['division_id']] = true;
        }

        $levels = $divisions !== [] && array_filter($divisions, fn (DivisionView $division): bool => ! isset($taught[$division->id])) === [];

        return [
            'school' => $this->school->detailsComplete(),
            'levels' => $levels,
            'year' => $year !== null && $year['periods'] !== [] && ($perYear === null || count($year['periods']) === $perYear),
            'classes' => $year !== null && $this->calendar->everyOfferingHasClasses($year['id']),
            'staff' => $this->invitationsWaiting() === 0 && $this->staffCount() > 1,
            'review' => $this->school->isOpen(),
        ];
    }

    /** @return list<string> the required steps not yet done */
    public function blockers(): array
    {
        $blockers = [];

        foreach ($this->steps() as $step => $done) {
            if (! $done && in_array($step, ['school', 'levels', 'year', 'classes'], true)) {
                $blockers[] = $step;
            }
        }

        if ($this->administrators() === 0) {
            $blockers[] = 'administrator';
        }

        return $blockers;
    }

    public function administrators(): int
    {
        return DB::table('access_role_assignments as a')
            ->join('access_roles as r', 'r.id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('r.key', Role::ADMINISTRATOR)->where('a.scope_type', 'institution')
            ->where('u.status', AccountStatus::Active->value)
            ->where('a.starts_at', '<=', now())
            ->where(fn (Builder $q): Builder => $q->whereNull('a.ends_at')->orWhere('a.ends_at', '>', now()))
            ->distinct()->count('a.user_id');
    }

    public function invitationsWaiting(): int
    {
        return DB::table('users')->where('status', AccountStatus::Invited->value)->whereNull('invitation_sent_at')->count();
    }

    private function staffCount(): int
    {
        return DB::table('users')->whereIn('status', [AccountStatus::Active->value, AccountStatus::Invited->value])->count();
    }
}
