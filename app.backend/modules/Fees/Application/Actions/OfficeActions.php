<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Fees\Application\FeeOffices;
use Acadly\Modules\Fees\Contracts\FeesAudit;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The school's finance offices (D-099): one office for the whole school (no
 * named office), or the offerings split among named offices. An offering no
 * office names stays with the school's own office. A school-wide setting.
 * Each office names its staff (D-100); every addition and removal is audited.
 */
final class OfficeActions
{
    private const string NOT_STAFF = 'Each person named must hold a fees role for every level the office serves.';

    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
        private readonly FeeOffices $places,
    ) {}

    /**
     * @param  list<array{id: string|null, name: string, offerings: list<string>, staff: list<string>}>  $offices
     * @param  list<string>  $ownStaff  the staff of the school's own office
     */
    public function save(User $by, array $offices, array $ownStaff = []): void
    {
        $this->access->check($by, FeesPermission::Manage);
        $known = FeeOffices::offerings();
        $seen = [];
        $errors = [];

        foreach ($offices as $i => $o) {
            if (trim($o['name']) === '' || mb_strlen(trim($o['name'])) > 80) {
                $errors["offices.{$i}.name"] = ['Give the office a name.'];
            }
            if ($o['offerings'] === []) {
                $errors["offices.{$i}.offerings"] = ['Choose what the office serves.'];
            }
            foreach ($o['offerings'] as $offering) {
                if (! in_array($offering, $known, true)) {
                    $errors["offices.{$i}.offerings"] = ['Choose a level taught at a campus of the school.'];
                } elseif (isset($seen[$offering])) {
                    $errors["offices.{$i}.offerings"] = ['Each level at a campus is served by one office only.'];
                }
                $seen[$offering] = true;
            }
        }

        // The school's own office serves what no named office serves.
        $rest = array_values(array_diff($known, array_keys($seen)));
        foreach ($offices as $i => $o) {
            if (! $this->mayWork($o['staff'], $o['offerings'])) {
                $errors["offices.{$i}.staff"] = [self::NOT_STAFF];
            }
        }
        if ($rest === [] && $ownStaff !== []) {
            $errors['own_staff'] = ['The school\'s own office serves nothing, so it has no staff.'];
        } elseif (! $this->mayWork($ownStaff, $rest)) {
            $errors['own_staff'] = [self::NOT_STAFF];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($by, $offices, $ownStaff, $rest): void {
            $now = $this->clock->now();
            $keep = [];

            foreach ($offices as $i => $o) {
                $id = $o['id'] !== null && DB::table('fee_offices')->where('id', $o['id'])->exists() ? $o['id'] : Ids::next();
                $keep[] = $id;
                DB::table('fee_offices')->updateOrInsert(['id' => $id], ['name' => trim($o['name']), 'position' => $i + 1, 'updated_at' => $now, 'created_at' => $now]);
                DB::table('fee_office_offerings')->where('office_id', $id)->delete();
                foreach ($o['offerings'] as $offering) {
                    DB::table('fee_office_offerings')->where('offering_id', $offering)->delete();
                    DB::table('fee_office_offerings')->insert(['office_id' => $id, 'offering_id' => $offering]);
                }
                $this->staff($by, $id, $o['staff'], $now);
            }
            $this->staff($by, FeeOffices::SCHOOL, $rest === [] ? [] : $ownStaff, $now);

            // An office no longer named: what it served goes back to the school's own office. Its closed days stay.
            $gone = DB::table('fee_offices')->whereNotIn('id', $keep ?: ['00000000-0000-0000-0000-000000000000'])->pluck('id')->all();
            DB::table('fee_office_offerings')->whereIn('office_id', $gone)->delete();
            foreach ($gone as $g) {
                $this->staff($by, is_string($g) ? $g : '', [], $now);
            }
            DB::table('fee_offices')->whereIn('id', $gone)->delete();

            $this->audit->record(FeesAudit::OfficesSaved, actor: $this->audit->actorFor($by), meta: ['offices' => count($offices)]);
        });
    }

    /**
     * Whether every person named holds a fees role for every level the office serves.
     *
     * @param  list<string>  $staff
     * @param  list<string>  $offerings
     */
    private function mayWork(array $staff, array $offerings): bool
    {
        foreach (array_unique($staff) as $id) {
            $person = User::query()->find($id);
            if (! $person instanceof User || ! $this->places->mayWork($person, $offerings)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sets who works in one office, recording each person added and removed.
     *
     * @param  list<string>  $staff
     */
    private function staff(User $by, string $officeId, array $staff, mixed $now): void
    {
        $before = FeeOffices::staffOf($officeId);
        $staff = array_values(array_unique($staff));
        $added = array_values(array_diff($staff, $before));
        $removed = array_values(array_diff($before, $staff));

        if ($removed !== []) {
            DB::table('fee_office_staff')->where('office_id', $officeId)->whereIn('user_id', $removed)->delete();
        }
        foreach ($added as $id) {
            DB::table('fee_office_staff')->insert(['office_id' => $officeId, 'user_id' => $id, 'added_by' => $by->id, 'added_at' => $now]);
        }
        if ($added !== [] || $removed !== []) {
            $this->audit->record(FeesAudit::OfficeStaffChanged, actor: $this->audit->actorFor($by), meta: ['office' => $officeId, 'added' => implode(',', $added), 'removed' => implode(',', $removed)]);
        }
    }
}
