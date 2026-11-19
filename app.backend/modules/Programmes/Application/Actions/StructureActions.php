<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Application\Place;
use Acadly\Modules\Programmes\Contracts\ProgrammesAudit;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A division's faculties and departments (D-097). A faculty or department in use is retired, never deleted. */
final class StructureActions
{
    public function __construct(
        private readonly Place $place,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @param  list<array{id: string|null, name: string, code: string, retired: bool}>  $faculties */
    public function saveFaculties(User $by, string $divisionId, array $faculties): void
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Manage);
        $codes = [];

        foreach ($faculties as $f) {
            $code = self::code($f['code']);

            if (trim($f['name']) === '' || $code === null) {
                throw ValidationException::withMessages(['faculties' => ['Give each faculty a name and a short code of 2 to 12 capital letters or digits.']]);
            }

            if (! $f['retired']) {
                if (isset($codes[$code])) {
                    throw ValidationException::withMessages(['faculties' => ["The code {$code} is given twice."]]);
                }
                $codes[$code] = true;
            }

            if ($f['id'] !== null) {
                Place::owned('programme_faculties', $f['id'], $divisionId);

                if ($f['retired'] && DB::table('programme_departments')->where('faculty_id', $f['id'])->whereNull('retired_at')->exists()) {
                    throw ValidationException::withMessages(['faculties' => ['Retire the departments of a faculty before the faculty.']]);
                }
            }
        }

        DB::transaction(function () use ($by, $divisionId, $faculties): void {
            $now = $this->clock->now();

            foreach ($faculties as $i => $f) {
                $row = ['name' => mb_substr(trim($f['name']), 0, 120), 'code' => self::code($f['code']), 'position' => $i + 1, 'updated_at' => $now];

                if ($f['id'] === null) {
                    DB::table('programme_faculties')->insert([...$row, 'id' => Ids::next(), 'division_id' => $divisionId, 'created_at' => $now,
                        'retired_at' => $f['retired'] ? $now : null]);
                } else {
                    DB::table('programme_faculties')->where('id', $f['id'])->update([...$row, 'retired_at' => $f['retired'] ? DB::raw('coalesce(retired_at, now())') : null]);
                }
            }

            $this->audit->record(ProgrammesAudit::FacultiesSaved, actor: $this->audit->actorFor($by), meta: ['faculties' => count($faculties)]);
        });
    }

    /**
     * Adds or changes one department.
     *
     * @param  array{id: string|null, faculty_id: string, name: string, code: string, prefixes: list<string>, head_user_id: string|null, retired: bool}  $d
     */
    public function saveDepartment(User $by, string $divisionId, array $d): string
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Manage);
        $faculty = Place::owned('programme_faculties', $d['faculty_id'], $divisionId);
        $code = self::code($d['code']);
        $prefixes = [];

        foreach ($d['prefixes'] as $p) {
            $p = strtoupper(trim($p));
            if (preg_match('/^[A-Z]{2,5}$/', $p) !== 1) {
                throw ValidationException::withMessages(['prefixes' => ['A course prefix is 2 to 5 capital letters, as ACC.']]);
            }
            $prefixes[$p] = true;
        }

        if (trim($d['name']) === '' || $code === null) {
            throw ValidationException::withMessages(['name' => ['Give the department a name and a short code of 2 to 12 capital letters or digits.']]);
        }

        if (! $d['retired'] && Row::maybeText($faculty, 'retired_at') !== null) {
            throw ValidationException::withMessages(['faculty_id' => ['That faculty is retired.']]);
        }

        if ($d['head_user_id'] !== null && ! DB::table('users')->where('id', $d['head_user_id'])->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['head_user_id' => ['Choose an active member of staff.']]);
        }

        $clash = DB::table('programme_departments as d')->join('programme_faculties as f', 'f.id', '=', 'd.faculty_id')
            ->where('f.division_id', $divisionId)->where('d.code', $code)->whereNull('d.retired_at')
            ->when($d['id'] !== null, fn ($q) => $q->where('d.id', '!=', $d['id']))->exists();
        if ($clash && ! $d['retired']) {
            throw ValidationException::withMessages(['code' => ["Another department has the code {$code}."]]);
        }

        if ($d['id'] !== null) {
            $own = DB::table('programme_departments as d')->join('programme_faculties as f', 'f.id', '=', 'd.faculty_id')
                ->where('d.id', $d['id'])->where('f.division_id', $divisionId)->exists();
            if (! $own) {
                throw ValidationException::withMessages(['id' => ['That department is not in this division.']]);
            }

            $inUse = DB::table('programmes')->where('department_id', $d['id'])->whereNull('retired_at')->exists()
                || DB::table('programme_courses')->where('department_id', $d['id'])->whereNull('retired_at')->exists();
            if ($d['retired'] && $inUse) {
                throw ValidationException::withMessages(['retired' => ['Retire the department\'s programmes and courses first.']]);
            }
        }

        return DB::transaction(function () use ($by, $d, $code, $prefixes): string {
            $now = $this->clock->now();
            $row = ['faculty_id' => $d['faculty_id'], 'name' => mb_substr(trim($d['name']), 0, 120), 'code' => $code,
                'prefixes' => json_encode(array_keys($prefixes)), 'head_user_id' => $d['head_user_id'], 'updated_at' => $now];
            $id = $d['id'] ?? Ids::next();

            if ($d['id'] === null) {
                DB::table('programme_departments')->insert([...$row, 'id' => $id, 'created_at' => $now, 'retired_at' => $d['retired'] ? $now : null]);
            } else {
                DB::table('programme_departments')->where('id', $id)->update([...$row, 'retired_at' => $d['retired'] ? DB::raw('coalesce(retired_at, now())') : null]);
            }

            $this->audit->record(ProgrammesAudit::DepartmentSaved, actor: $this->audit->actorFor($by), meta: ['department' => $id, 'code' => $code]);

            return $id;
        });
    }

    public static function code(string $code): ?string
    {
        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z0-9][A-Z0-9-]{1,11}$/', $code) === 1 ? $code : null;
    }
}
