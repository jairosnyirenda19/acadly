<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Application\Actions;

use Acadly\Core\Institution\Contracts\InstitutionAudit;
use Acadly\Core\Institution\Contracts\InstitutionPermission;
use Acadly\Core\Institution\Domain\Campus;
use Acadly\Core\Institution\Domain\Division;
use Acadly\Core\Institution\Domain\School;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;

/** Says whether a level is taught at a campus. Switching one off ends the row; it is never deleted. */
final class SetOffering
{
    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function handle(User $by, Division $division, Campus $campus, bool $taught): void
    {
        $this->access->check($by, InstitutionPermission::Manage);

        DB::transaction(function () use ($by, $division, $campus, $taught): void {
            $active = DB::table('institution_offerings')->where('division_id', $division->id)->where('campus_id', $campus->id)->whereNull('ended_at');

            if ($active->exists() === $taught) {
                return;
            }

            $taught
                ? DB::table('institution_offerings')->insert(['id' => Ids::next(), 'division_id' => $division->id, 'campus_id' => $campus->id, 'started_at' => $this->clock->now()])
                : $active->update(['ended_at' => $this->clock->now()]);

            $this->audit->record(InstitutionAudit::OfferingSet, subject: School::current(), actor: $this->audit->actorFor($by),
                meta: ['division' => $division->name, 'campus' => $campus->name, 'taught' => $taught]);
        });
    }
}
