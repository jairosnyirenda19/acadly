<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicsAudit;
use Acadly\Core\Academics\Contracts\AcademicsPermission;
use Acadly\Core\Academics\Domain\AcademicYear;
use Acadly\Core\Institution\Contracts\DivisionView;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets the classes of one level at one campus for a year. The levels come
 * from the division's profile and cannot be renamed here. A level with no
 * stream is one class, named after the level. A class that is no longer
 * wanted is ended, never deleted.
 */
final class SaveClasses
{
    public function __construct(
        private readonly Access $access,
        private readonly SchoolDirectory $school,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, list<string>>  $streams  level code => stream names, an empty list for one class
     * @return int the classes in force for the offering
     */
    public function handle(User $by, AcademicYear $year, string $divisionId, string $campusId, array $streams): int
    {
        $this->access->check($by, AcademicsPermission::Manage, ScopeRef::of(ScopeType::Campus, $campusId));

        $division = $this->school->division($divisionId);

        if (! $division instanceof DivisionView || $this->school->campus($campusId) === null || ! $this->school->offers($divisionId, $campusId)) {
            throw ValidationException::withMessages(['offering' => ['The level is not taught at this campus.']]);
        }

        $wanted = $this->wanted($division, $streams);

        return DB::transaction(function () use ($by, $year, $division, $campusId, $wanted): int {
            $now = $this->clock->now();
            $existing = DB::table('academic_classes')->where('year_id', $year->id)->where('division_id', $division->id)
                ->where('campus_id', $campusId)->whereNull('ended_at')->get(['id', 'level_code', 'stream']);
            $kept = [];

            foreach ($existing as $row) {
                $key = Row::text($row, 'level_code').'|'.(Row::maybeText($row, 'stream') ?? '');

                if (isset($wanted[$key])) {
                    $kept[$key] = true;
                    DB::table('academic_classes')->where('id', Row::text($row, 'id'))->update(['position' => $wanted[$key]['position'], 'updated_at' => $now]);
                } else {
                    DB::table('academic_classes')->where('id', Row::text($row, 'id'))->update(['ended_at' => $now, 'updated_at' => $now]);
                }
            }

            foreach ($wanted as $key => $class) {
                if (! isset($kept[$key])) {
                    DB::table('academic_classes')->insert([
                        'id' => Ids::next(),
                        'year_id' => $year->id,
                        'division_id' => $division->id,
                        'campus_id' => $campusId,
                        'level_code' => $class['level'],
                        'stream' => $class['stream'],
                        'name' => $class['name'],
                        'position' => $class['position'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $this->audit->record(AcademicsAudit::ClassesSaved, subject: $year, actor: $this->audit->actorFor($by),
                at: ScopeRef::of(ScopeType::Campus, $campusId), meta: ['division' => $division->name, 'classes' => count($wanted)]);

            return count($wanted);
        });
    }

    /**
     * @param  array<string, list<string>>  $streams
     * @return array<string, array{level: string, stream: ?string, name: string, position: int}>
     */
    private function wanted(DivisionView $division, array $streams): array
    {
        $wanted = [];
        $position = 0;

        foreach ($division->profile->levels as $level) {
            $names = [];

            foreach ($streams[$level['code']] ?? [] as $stream) {
                $name = trim($stream);

                if ($name === '' || mb_strlen($name) > 30) {
                    throw ValidationException::withMessages(["streams.{$level['code']}" => ['A stream name has 1 to 30 characters.']]);
                }

                if (isset($names[mb_strtolower($name)])) {
                    throw ValidationException::withMessages(["streams.{$level['code']}" => ["{$level['label']} has the stream {$name} twice."]]);
                }

                $names[mb_strtolower($name)] = $name;
            }

            if ($names === []) {
                $wanted[$level['code'].'|'] = ['level' => $level['code'], 'stream' => null, 'name' => $level['label'], 'position' => ++$position];
            }

            foreach ($names as $name) {
                $wanted[$level['code'].'|'.$name] = ['level' => $level['code'], 'stream' => $name, 'name' => $level['label'].' '.$name, 'position' => ++$position];
            }
        }

        foreach (array_keys($streams) as $code) {
            if (! $division->profile->hasLevel($code)) {
                throw ValidationException::withMessages(['streams' => ["{$division->name} has no level {$code}."]]);
            }
        }

        return $wanted;
    }
}
