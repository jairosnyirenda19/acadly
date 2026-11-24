<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Application\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Conduct\Contracts\ConductAudit;
use Acadly\Modules\Conduct\Contracts\ConductPermission;
use Acadly\Modules\Conduct\Domain\Level;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The school's conduct rules: kinds, when families are told, learners to watch (D-092). */
final class KindActions
{
    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * A kind in use is retired, not deleted. A serious kind always tells the family.
     *
     * @param  list<array{id: string|null, name: string, commendation: bool, level: Level|null, always: bool, retired: bool}>  $kinds
     */
    public function saveKinds(User $by, array $kinds): void
    {
        $this->access->check($by, ConductPermission::Manage);
        $names = [];

        foreach ($kinds as $k) {
            $name = mb_strtolower(trim($k['name']));

            if ($name === '' || (! $k['commendation'] && $k['level'] === null)) {
                throw ValidationException::withMessages(['kinds' => ['Give each kind a name, and each incident a level.']]);
            }
            if (! $k['retired']) {
                if (isset($names[$name])) {
                    throw ValidationException::withMessages(['kinds' => ['Give each kind once.']]);
                }
                $names[$name] = true;
            }
        }

        DB::transaction(function () use ($by, $kinds): void {
            $now = $this->clock->now();

            foreach ($kinds as $i => $k) {
                $always = $k['always'] || $k['level'] === Level::Serious;
                $row = ['name' => mb_substr(trim($k['name']), 0, 80), 'commendation' => $k['commendation'], 'level' => $k['commendation'] ? null : $k['level']?->value,
                    'tell' => $always ? 'always' : 'ticked', 'position' => $i + 1, 'updated_at' => $now];

                if ($k['id'] === null) {
                    DB::table('conduct_kinds')->insert([...$row, 'id' => Ids::next(), 'created_at' => $now, 'retired_at' => $k['retired'] ? $now : null]);
                } else {
                    DB::table('conduct_kinds')->where('id', $k['id'])->update([...$row, 'retired_at' => $k['retired'] ? DB::raw('coalesce(retired_at, now())') : null]);
                }
            }

            $this->audit->record(ConductAudit::KindsSaved, actor: $this->audit->actorFor($by), meta: ['kinds' => count($kinds)]);
        });
    }

    /**
     * @param  array{incidents: int, days: int, open_serious: bool, missed_incidents: int, missed_lessons: int, missed_days: int, portal: bool}  $rules
     */
    public function saveWatch(User $by, array $rules): void
    {
        $this->access->check($by, ConductPermission::Manage);

        if ($rules['incidents'] < 1 || $rules['incidents'] > 50 || $rules['days'] < 1 || $rules['days'] > 365) {
            throw ValidationException::withMessages(['watch' => ['Give from 1 to 50 incidents in from 1 to 365 days.']]);
        }
        if ($rules['missed_incidents'] < 0 || $rules['missed_incidents'] > 50 || $rules['missed_lessons'] < 1 || $rules['missed_lessons'] > 200
            || $rules['missed_days'] < 1 || $rules['missed_days'] > 90) {
            throw ValidationException::withMessages(['missed' => ['Give from 0 to 50 incidents, from 1 to 200 missed lessons, and from 1 to 90 days. 0 incidents turns the rule off.']]);
        }

        DB::transaction(function () use ($by, $rules): void {
            DB::table('conduct_watch')->updateOrInsert(['id' => 1], [...$rules, 'updated_at' => $this->clock->now()]);
            $this->audit->record(ConductAudit::WatchSaved, actor: $this->audit->actorFor($by), meta: $rules);
        });
    }
}
