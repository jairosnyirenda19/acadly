<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Application;

use Acadly\Core\Institution\Contracts\CampusView;
use Acadly\Core\Institution\Contracts\DivisionView;
use Acadly\Core\Institution\Contracts\InstitutionAudit;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Core\Institution\Domain\Campus;
use Acadly\Core\Institution\Domain\Division;
use Acadly\Core\Institution\Domain\School;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Blueprint\BlueprintRef;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The school's structure, read from its own tables. Bound as the Institution
 * contract for other domains and as the kernel's `SchoolProfile`.
 */
final class InstitutionDirectory implements SchoolDirectory, SchoolProfile
{
    public function __construct(
        private readonly Blueprints $blueprints,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function name(): string
    {
        return School::current()->name;
    }

    public function shortName(): string
    {
        $school = School::current();

        return $school->short_name ?? mb_substr($school->name, 0, 16);
    }

    public function hasLogo(): bool
    {
        return School::current()->logo_document_id !== null;
    }

    public function isOpen(): bool
    {
        return School::current()->isOpen();
    }

    public function detailsComplete(): bool
    {
        $school = School::current();

        foreach ([$school->short_name, $school->code, $school->email, $school->phone, $school->district, $school->country] as $value) {
            if ($value === null || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    /** @return list<DivisionView> */
    public function divisionViews(): array
    {
        return array_values(Division::query()->inForce()->get()
            ->map(fn (Division $division): DivisionView => $this->view($division))->all());
    }

    public function divisions(): array
    {
        return array_map(
            fn (DivisionView $division): array => ['id' => $division->id, 'name' => $division->name, 'vocabulary' => $division->profile->vocabulary],
            $this->divisionViews(),
        );
    }

    public function division(string $id): ?DivisionView
    {
        $division = Division::query()->whereNull('ended_at')->find($id);

        return $division instanceof Division ? $this->view($division) : null;
    }

    /** @return list<CampusView> */
    public function campusViews(): array
    {
        return array_values(Campus::query()->inForce()->get()
            ->map(fn (Campus $campus): CampusView => new CampusView($campus->id, $campus->name, $campus->place, $campus->address))->all());
    }

    public function campuses(): array
    {
        return array_map(fn (CampusView $campus): array => ['id' => $campus->id, 'name' => $campus->name], $this->campusViews());
    }

    public function campus(string $id): ?CampusView
    {
        $campus = Campus::query()->whereNull('ended_at')->find($id);

        return $campus instanceof Campus ? new CampusView($campus->id, $campus->name, $campus->place, $campus->address) : null;
    }

    public function offerings(): array
    {
        return array_values(DB::table('institution_offerings as o')
            ->join('institution_divisions as d', 'd.id', '=', 'o.division_id')
            ->join('institution_campuses as c', 'c.id', '=', 'o.campus_id')
            ->whereNull('o.ended_at')->whereNull('d.ended_at')->whereNull('c.ended_at')
            ->orderBy('d.position')->orderBy('c.position')
            ->get(['o.division_id', 'o.campus_id'])
            ->map(fn (object $row): array => ['division_id' => Row::text($row, 'division_id'), 'campus_id' => Row::text($row, 'campus_id')])
            ->all());
    }

    public function offers(string $divisionId, string $campusId): bool
    {
        foreach ($this->offerings() as $offering) {
            if ($offering['division_id'] === $divisionId && $offering['campus_id'] === $campusId) {
                return true;
            }
        }

        return false;
    }

    public function open(User $by): void
    {
        DB::transaction(function () use ($by): void {
            $school = School::query()->lockForUpdate()->firstOrFail();

            if ($school->isOpen()) {
                throw ValidationException::withMessages(['school' => ['The school is already open.']]);
            }

            $school->forceFill(['opened_at' => $this->clock->now(), 'opened_by' => $by->id])->save();

            $this->audit->record(InstitutionAudit::Opened, subject: $school, actor: $this->audit->actorFor($by), meta: ['code' => $school->code]);
        });
    }

    private function view(Division $division): DivisionView
    {
        return new DivisionView($division->id, $division->name, $this->blueprints->profile(BlueprintRef::parse($division->profile_ref)));
    }
}
