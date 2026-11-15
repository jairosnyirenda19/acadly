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
use Acadly\Kernel\Blueprint\BlueprintRef;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Compiled\CompiledProfile;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets the levels the school runs, by profile. A new level is a division
 * pinned to the newest version of its profile; a level kept keeps its pin;
 * a level dropped is ended, with its offerings. Before the school opens,
 * levels can change freely; after, dropping one is refused here (it needs
 * the journeys that move learners out first).
 *
 * The first time levels are set and the school has no campus, one campus
 * is made from the school's district, so a single-site school never has to
 * think about campuses.
 */
final class SetLevels
{
    public function __construct(
        private readonly Access $access,
        private readonly Blueprints $blueprints,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<array{profile: string, name?: string|null}>  $levels  profile keys, such as PRIMARY, in the order to show
     * @return list<Division>
     */
    public function handle(User $by, array $levels): array
    {
        $this->access->check($by, InstitutionPermission::Manage);

        if ($levels === []) {
            throw ValidationException::withMessages(['levels' => ['Choose at least one level.']]);
        }

        $offered = [];

        foreach ($this->blueprints->pinnableProfiles() as $profile) {
            $offered[$profile->ref->key] = $profile;
        }

        $wanted = [];

        foreach ($levels as $level) {
            $profile = $offered[$level['profile']] ?? throw ValidationException::withMessages(['levels' => ['A level that is not available was chosen.']]);
            $name = trim((string) ($level['name'] ?? ''));

            if (isset($wanted[$profile->ref->key])) {
                throw ValidationException::withMessages(['levels' => ['Each level can be chosen once.']]);
            }

            $wanted[$profile->ref->key] = [$profile, $name !== '' ? mb_substr($name, 0, 80) : $profile->title];
        }

        return DB::transaction(function () use ($by, $wanted): array {
            $school = School::query()->lockForUpdate()->firstOrFail();
            $now = $this->clock->now();
            $current = Division::query()->whereNull('ended_at')->get()
                ->keyBy(fn (Division $division): string => BlueprintRef::parse($division->profile_ref)->key);

            foreach ($current as $key => $division) {
                if (! isset($wanted[$key])) {
                    if ($school->isOpen()) {
                        throw ValidationException::withMessages(['levels' => ["{$division->name} cannot be removed after the school has opened."]]);
                    }

                    $division->forceFill(['ended_at' => $now])->save();
                    DB::table('institution_offerings')->where('division_id', $division->id)->whereNull('ended_at')->update(['ended_at' => $now]);
                }
            }

            $position = 0;
            $result = [];

            /** @var array{CompiledProfile, string} $pair */
            foreach ($wanted as $key => $pair) {
                [$profile, $name] = $pair;
                $division = $current->get($key) ?? new Division(['profile_ref' => (string) $profile->ref]);
                $division->fill(['name' => $name, 'position' => ++$position])->save();
                $result[] = $division;
            }

            if (! Campus::query()->whereNull('ended_at')->exists()) {
                $campus = Campus::query()->create(['name' => 'Main campus', 'place' => $school->district ?? $school->name, 'position' => 1]);

                foreach ($result as $division) {
                    DB::table('institution_offerings')->insert([
                        'id' => Ids::next(),
                        'division_id' => $division->id,
                        'campus_id' => $campus->id,
                        'started_at' => $now,
                    ]);
                }
            }

            $this->audit->record(InstitutionAudit::LevelsSet, subject: $school, actor: $this->audit->actorFor($by),
                meta: ['levels' => implode(',', array_keys($wanted))]);

            return $result;
        });
    }
}
