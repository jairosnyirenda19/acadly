<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicsAudit;
use Acadly\Core\Academics\Contracts\AcademicsPermission;
use Acadly\Core\Academics\Domain\AcademicYear;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Core\Institution\Contracts\Term;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Input;
use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sets the terms of one level for the academic year. The number of terms and
 * their word come from the level's profile; the terms lie inside the year, in
 * order, without overlapping. A term saved again keeps its identity: it is
 * changed in place, so what is recorded against it stays attached.
 */
final class SaveTerms
{
    public function __construct(
        private readonly Access $access,
        private readonly SchoolDirectory $school,
        private readonly Audit $audit,
    ) {}

    /** @param  array<mixed>  $terms  [{name, starts_on, ends_on}] */
    public function handle(User $by, AcademicYear $year, string $divisionId, array $terms): void
    {
        $this->access->check($by, AcademicsPermission::Manage);

        $division = $this->school->division($divisionId)
            ?? throw ValidationException::withMessages(['division_id' => ['The school does not run this level.']]);
        $word = $division->profile->word(Term::Period->value);
        $plural = mb_strtolower($division->profile->word(Term::Period->value, true));

        $data = Validator::make(['terms' => $terms], [
            'terms' => ['required', 'array', 'min:1', 'max:6'],
            'terms.*.name' => ['required', 'string', 'max:40'],
            'terms.*.starts_on' => ['required', 'date_format:Y-m-d'],
            'terms.*.ends_on' => ['required', 'date_format:Y-m-d'],
        ])->validate();

        $rows = array_values(array_filter(is_array($data['terms']) ? $data['terms'] : [], 'is_array'));
        $expected = $division->profile->periodsPerYear;

        if ($expected !== null && count($rows) !== $expected) {
            throw ValidationException::withMessages(['terms' => ["{$division->name} uses {$expected} {$plural} a year."]]);
        }

        $start = self::date(substr($year->starts_on, 0, 10), 'starts_on');
        $end = self::date(substr($year->ends_on, 0, 10), 'ends_on');
        $previousEnd = null;

        foreach ($rows as $index => $row) {
            $field = "terms.{$index}";
            $from = self::date(Input::text($row, 'starts_on'), "{$field}.starts_on");
            $to = self::date(Input::text($row, 'ends_on'), "{$field}.ends_on");

            if ($to <= $from) {
                throw ValidationException::withMessages(["{$field}.ends_on" => ["A {$word} must end after it starts."]]);
            }

            if ($from < $start || $to > $end) {
                throw ValidationException::withMessages(["{$field}.starts_on" => ["A {$word} must lie inside the year."]]);
            }

            if ($previousEnd !== null && $from <= $previousEnd) {
                throw ValidationException::withMessages(["{$field}.starts_on" => ["A {$word} must start after the one before it ends."]]);
            }

            $previousEnd = $to;
        }

        DB::transaction(function () use ($by, $year, $divisionId, $rows): void {
            $existing = [];

            foreach (DB::table('academic_periods')->where('year_id', $year->id)->where('division_id', $divisionId)->get(['id', 'position']) as $row) {
                $existing[Row::int($row, 'position')] = Row::text($row, 'id');
            }

            $saved = [];

            foreach ($rows as $index => $row) {
                $position = $index + 1;
                $values = ['name' => Input::text($row, 'name'), 'starts_on' => Input::text($row, 'starts_on'), 'ends_on' => Input::text($row, 'ends_on'), 'updated_at' => now()];
                $saved[] = "{$values['name']}: {$values['starts_on']} to {$values['ends_on']}";

                if (isset($existing[$position])) {
                    DB::table('academic_periods')->where('id', $existing[$position])->update($values);
                } else {
                    DB::table('academic_periods')->insert($values + [
                        'id' => Ids::next(), 'year_id' => $year->id, 'division_id' => $divisionId,
                        'position' => $position, 'created_at' => now(),
                    ]);
                }
            }

            DB::table('academic_periods')->where('year_id', $year->id)->where('division_id', $divisionId)
                ->where('position', '>', count($rows))->delete();

            $this->audit->record(AcademicsAudit::TermsSaved, subject: $year, actor: $this->audit->actorFor($by),
                meta: ['division' => $divisionId, 'terms' => implode('; ', $saved)]);
        });
    }

    private static function date(string $value, string $field): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = null;
        }

        return $date instanceof CarbonImmutable ? $date : throw ValidationException::withMessages([$field => ['Use a date such as 2026-09-07.']]);
    }
}
