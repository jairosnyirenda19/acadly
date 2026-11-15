<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicsAudit;
use Acadly\Core\Academics\Contracts\AcademicsPermission;
use Acadly\Core\Academics\Domain\AcademicYear;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Input;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sets the school's academic year: its name, start and end. One year for the
 * whole school; each level keeps its own terms inside it (SaveTerms). A year
 * cannot be shortened past a term already set.
 */
final class SaveAcademicYear
{
    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
    ) {}

    /** @param  array<mixed>  $input  name, starts_on, ends_on */
    public function handle(User $by, ?AcademicYear $year, array $input): AcademicYear
    {
        $this->access->check($by, AcademicsPermission::Manage);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:40'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on'],
        ], [
            'ends_on.after' => 'The year must end after it starts.',
        ])->validate();

        $startsOn = Input::text($data, 'starts_on');
        $endsOn = Input::text($data, 'ends_on');

        if ($year !== null) {
            $outside = DB::table('academic_periods as p')
                ->join('institution_divisions as d', 'd.id', '=', 'p.division_id')
                ->where('p.year_id', $year->id)
                ->where(fn (Builder $q): Builder => $q->where('p.starts_on', '<', $startsOn)->orWhere('p.ends_on', '>', $endsOn))
                ->orderBy('d.position')->orderBy('p.position')
                ->first(['p.name', 'd.name as division']);

            if ($outside !== null) {
                throw ValidationException::withMessages(['starts_on' => [
                    Row::text($outside, 'name').' of '.Row::text($outside, 'division').' lies outside these dates. Change that term first.',
                ]]);
            }
        }

        return DB::transaction(function () use ($by, $year, $data, $startsOn, $endsOn): AcademicYear {
            $record = $year ?? new AcademicYear;
            $watch = $this->audit->watch($record);
            $record->fill(['name' => Input::text($data, 'name'), 'starts_on' => $startsOn, 'ends_on' => $endsOn])->save();

            $this->audit->record(AcademicsAudit::YearSaved, subject: $record, changes: $watch->diff(), actor: $this->audit->actorFor($by));

            return $record;
        });
    }
}
