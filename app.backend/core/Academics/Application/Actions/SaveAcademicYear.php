<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicsAudit;
use Acadly\Core\Academics\Contracts\AcademicsPermission;
use Acadly\Core\Academics\Domain\AcademicYear;
use Acadly\Core\Institution\Contracts\DivisionView;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Input;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sets an academic year and its terms. The number of terms comes from the
 * levels' profiles; the terms lie inside the year, in order, without
 * overlapping. Saving the same year again replaces its terms.
 */
final class SaveAcademicYear
{
    public function __construct(
        private readonly Access $access,
        private readonly SchoolDirectory $school,
        private readonly Audit $audit,
    ) {}

    /** @param  array<mixed>  $input  name, starts_on, ends_on, terms: [{name, starts_on, ends_on}] */
    public function handle(User $by, ?AcademicYear $year, array $input): AcademicYear
    {
        $this->access->check($by, AcademicsPermission::Manage);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:40'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on'],
            'terms' => ['required', 'array', 'min:1', 'max:6'],
            'terms.*.name' => ['required', 'string', 'max:40'],
            'terms.*.starts_on' => ['required', 'date_format:Y-m-d'],
            'terms.*.ends_on' => ['required', 'date_format:Y-m-d'],
        ], [
            'ends_on.after' => 'The year must end after it starts.',
        ])->validate();

        $expected = $this->periodsPerYear();
        $terms = array_values(array_filter(is_array($data['terms']) ? $data['terms'] : [], 'is_array'));

        if ($expected !== null && count($terms) !== $expected) {
            throw ValidationException::withMessages(['terms' => ["The levels of the school use {$expected} terms a year."]]);
        }

        $start = self::date(Input::text($data, 'starts_on'));
        $end = self::date(Input::text($data, 'ends_on'));
        $previousEnd = null;

        foreach ($terms as $index => $term) {
            $from = self::date(Input::text($term, 'starts_on'));
            $to = self::date(Input::text($term, 'ends_on'));
            $field = "terms.{$index}";

            if ($to <= $from) {
                throw ValidationException::withMessages(["{$field}.ends_on" => ['A term must end after it starts.']]);
            }

            if ($from < $start || $to > $end) {
                throw ValidationException::withMessages(["{$field}.starts_on" => ['A term must lie inside the year.']]);
            }

            if ($previousEnd !== null && $from <= $previousEnd) {
                throw ValidationException::withMessages(["{$field}.starts_on" => ['A term must start after the term before it ends.']]);
            }

            $previousEnd = $to;
        }

        return DB::transaction(function () use ($by, $year, $data, $terms): AcademicYear {
            $record = $year ?? new AcademicYear;
            $watch = $this->audit->watch($record);
            $record->fill(['name' => Input::text($data, 'name'), 'starts_on' => Input::text($data, 'starts_on'), 'ends_on' => Input::text($data, 'ends_on')])->save();

            DB::table('academic_periods')->where('year_id', $record->id)->delete();

            foreach ($terms as $index => $term) {
                DB::table('academic_periods')->insert([
                    'id' => Ids::next(),
                    'year_id' => $record->id,
                    'name' => Input::text($term, 'name'),
                    'position' => $index + 1,
                    'starts_on' => Input::text($term, 'starts_on'),
                    'ends_on' => Input::text($term, 'ends_on'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->audit->record(AcademicsAudit::YearSaved, subject: $record, changes: $watch->diff(),
                actor: $this->audit->actorFor($by), meta: ['terms' => count($terms)]);

            return $record;
        });
    }

    /** The terms a year the levels use, when they agree. */
    private function periodsPerYear(): ?int
    {
        $counts = array_values(array_unique(array_filter(array_map(
            fn (DivisionView $division): ?int => $division->profile->periodsPerYear,
            $this->school->divisionViews(),
        ))));

        if (count($counts) > 1) {
            throw ValidationException::withMessages(['terms' => ['The levels of the school divide the year differently. One shared calendar is not yet supported.']]);
        }

        return $counts[0] ?? null;
    }

    private static function date(string $value): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = null;
        }

        return $date instanceof CarbonImmutable ? $date : throw ValidationException::withMessages(['starts_on' => ['Use a date such as 2026-09-07.']]);
    }
}
