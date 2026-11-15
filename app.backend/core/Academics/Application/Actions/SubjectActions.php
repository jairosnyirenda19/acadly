<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicsAudit;
use Acadly\Core\Academics\Contracts\AcademicsPermission;
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
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The subjects of a level (the approved Subjects screen). A level starts with
 * the subjects of its profile; from then on the list is the school's own. A
 * subject no longer taught is retired: its history stays, and whoever taught
 * it stops teaching it.
 */
final class SubjectActions
{
    public function __construct(
        private readonly Access $access,
        private readonly SchoolDirectory $school,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** Starts a level's subjects from its profile. Only while the level has none. */
    public function start(User $by, string $divisionId): int
    {
        $division = $this->division($by, $divisionId);

        if (DB::table('academic_subjects')->where('division_id', $division->id)->exists()) {
            throw ValidationException::withMessages(['division_id' => ['This level already has its subjects.']]);
        }

        return DB::transaction(function () use ($by, $division): int {
            foreach ($division->profile->subjects as $i => $subject) {
                DB::table('academic_subjects')->insert([
                    'id' => Ids::next(), 'division_id' => $division->id, 'short' => $subject['short'], 'name' => $subject['name'],
                    'core' => $subject['core'], 'levels' => json_encode($subject['levels'], JSON_THROW_ON_ERROR), 'position' => $i + 1,
                    'created_at' => $this->clock->now(), 'updated_at' => $this->clock->now(),
                ]);
            }

            $count = count($division->profile->subjects);
            $this->audit->record(AcademicsAudit::SubjectsStarted, actor: $this->audit->actorFor($by),
                at: ScopeRef::of(ScopeType::Division, $division->id), meta: ['division' => $division->id, 'subjects' => $count]);

            return $count;
        });
    }

    /**
     * Adds a subject (no id) or changes one.
     *
     * @param  list<string>  $levels  level codes of the level's ladder; none is allowed and flagged on the screen
     */
    public function save(User $by, ?string $id, string $divisionId, string $name, string $short, bool $core, array $levels): string
    {
        $division = $this->division($by, $divisionId);
        $name = trim($name);
        $short = mb_strtoupper(trim($short));

        if ($name === '' || mb_strlen($name) > 60) {
            throw ValidationException::withMessages(['name' => ['Give the subject a name of up to 60 characters.']]);
        }

        if (preg_match('/^[A-Z][A-Z0-9]{1,3}$/', $short) !== 1) {
            throw ValidationException::withMessages(['short' => ['The short name is 2 to 4 capital letters or digits, such as MAT.']]);
        }

        foreach ($levels as $code) {
            if (! $division->profile->hasLevel($code)) {
                throw ValidationException::withMessages(['levels' => ["{$division->name} has no {$code}."]]);
            }
        }

        $same = DB::table('academic_subjects')->where('division_id', $division->id)->whereNull('retired_at')
            ->where(fn (Builder $q): Builder => $q->where('short', $short)->orWhereRaw('lower(name) = ?', [mb_strtolower($name)]));

        if ($id !== null) {
            $same->where('id', '!=', $id);
        }

        if ($same->exists()) {
            throw ValidationException::withMessages(['short' => ['Another subject of this level has this name or short name.']]);
        }

        $levels = array_values(array_unique($levels));

        return DB::transaction(function () use ($by, $id, $division, $name, $short, $core, $levels): string {
            $values = ['name' => $name, 'short' => $short, 'core' => $core, 'levels' => json_encode($levels, JSON_THROW_ON_ERROR), 'updated_at' => $this->clock->now()];

            if ($id === null) {
                $id = Ids::next();
                $position = DB::table('academic_subjects')->where('division_id', $division->id)->max('position');
                DB::table('academic_subjects')->insert($values + ['id' => $id, 'division_id' => $division->id,
                    'position' => (is_numeric($position) ? (int) $position : 0) + 1, 'created_at' => $this->clock->now()]);
            } else {
                $updated = DB::table('academic_subjects')->where('id', $id)->where('division_id', $division->id)->whereNull('retired_at')->update($values);

                if ($updated === 0) {
                    throw ValidationException::withMessages(['subject' => ['The subject does not exist or has been retired.']]);
                }
            }

            $this->audit->record(AcademicsAudit::SubjectSaved, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $division->id),
                meta: ['subject' => $id, 'short' => $short, 'name' => $name, 'core' => $core, 'levels' => implode(',', $levels)]);

            return $id;
        });
    }

    /** Retires a subject: it is no longer taught, and its teachers stop teaching it. */
    public function retire(User $by, string $id, string $reason): void
    {
        $row = DB::table('academic_subjects')->where('id', $id)->whereNull('retired_at')->first(['division_id', 'short']);

        if ($row === null) {
            $this->access->check($by, AcademicsPermission::Manage);

            throw ValidationException::withMessages(['subject' => ['The subject does not exist or has been retired.']]);
        }

        $division = $this->division($by, Row::text($row, 'division_id'));

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        DB::transaction(function () use ($by, $id, $division, $row, $reason): void {
            $now = $this->clock->now();
            DB::table('academic_subjects')->where('id', $id)->update(['retired_at' => $now, 'updated_at' => $now]);
            DB::table('academic_teaching')->where('subject_id', $id)->whereNull('ended_at')->update(['ended_at' => $now, 'ended_by' => $by->id]);

            $this->audit->record(AcademicsAudit::SubjectRetired, reason: trim($reason), actor: $this->audit->actorFor($by),
                at: ScopeRef::of(ScopeType::Division, $division->id), meta: ['subject' => $id, 'short' => Row::text($row, 'short')]);
        });
    }

    /** The division, when the person may manage it; refused before anything else. */
    private function division(User $by, string $divisionId): DivisionView
    {
        try {
            $at = ScopeRef::of(ScopeType::Division, $divisionId);
        } catch (\InvalidArgumentException) {
            $at = ScopeRef::institution();
        }

        $this->access->check($by, AcademicsPermission::Manage, $at);

        return $this->school->division($divisionId) ?? throw ValidationException::withMessages(['division_id' => ['The school does not run this level.']]);
    }
}
