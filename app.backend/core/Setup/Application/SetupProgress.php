<?php

declare(strict_types=1);

namespace Acadly\Core\Setup\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Institution\Contracts\DivisionView;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Identity\AccountStatus;
use Illuminate\Support\Facades\DB;

/**
 * Where setup stands, step by step (the approved setup screens). A step is
 * done when what it sets is complete; staff is optional. The school can open
 * when every required step is done and an active account can give roles for
 * the whole school.
 */
final class SetupProgress
{
    public const array STEPS = ['school', 'levels', 'year', 'classes', 'staff', 'review'];

    public function __construct(
        private readonly SchoolDirectory $school,
        private readonly AcademicCalendar $calendar,
        private readonly Access $access,
    ) {}

    /** @return array<string, bool> step => done */
    public function steps(): array
    {
        $year = $this->calendar->currentYear();
        $divisions = $this->school->divisionViews();
        $offerings = $this->school->offerings();
        $taught = [];

        foreach ($offerings as $offering) {
            $taught[$offering['division_id']] = true;
        }

        $levels = $divisions !== [] && array_filter($divisions, fn (DivisionView $division): bool => ! isset($taught[$division->id])) === [];

        return [
            'school' => $this->school->detailsComplete(),
            'levels' => $levels,
            'year' => $year !== null && $divisions !== [] && array_filter($divisions, fn (DivisionView $division): bool => ! self::termsSet($division, $year['terms'][$division->id] ?? [])) === [],
            'classes' => $year !== null && $this->calendar->everyOfferingHasClasses($year['id']),
            'staff' => $this->invitationsWaiting() === 0 && $this->staffCount() > 1,
            'review' => $this->school->isOpen(),
        ];
    }

    /** @param  list<mixed>  $terms */
    private static function termsSet(DivisionView $division, array $terms): bool
    {
        $expected = $division->profile->periodsPerYear;

        return $terms !== [] && ($expected === null || count($terms) === $expected);
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

    /** Active accounts that can give roles for the whole school, whatever their roles are called. */
    public function administrators(): int
    {
        return $this->access->holders(AccessPermission::AssignRoles, ScopeRef::institution());
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
