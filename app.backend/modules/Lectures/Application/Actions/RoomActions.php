<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application\Actions;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Lectures\Application\Where;
use Acadly\Modules\Lectures\Contracts\LecturesAudit;
use Acadly\Modules\Lectures\Contracts\LecturesPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rooms, the campus areas a scan must come from, and how long scanning
 * stays open (D-102). A room in use is retired, never deleted.
 */
final class RoomActions
{
    public const array KINDS = ['hall', 'room', 'laboratory', 'seminar'];

    public const int DEFAULT_MINUTES = 20;

    public function __construct(
        private readonly Where $where,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array{id: string|null, code: string, name: string, seats: int, kind: string, retired: bool}  $input
     * @return string the room's id
     */
    public function saveRoom(User $by, string $divisionId, array $input): string
    {
        $this->where->division($by, $divisionId, LecturesPermission::Timetable);
        $code = mb_strtoupper(trim($input['code']));
        $name = trim($input['name']);
        $errors = [];
        if ($code === '' || mb_strlen($code) > 16) {
            $errors['code'] = ['Give the room a code of up to 16 characters.'];
        }
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = ['Give the room a name.'];
        }
        if ($input['seats'] < 1 || $input['seats'] > 2000) {
            $errors['seats'] = ['Give the number of seats, from 1 to 2,000.'];
        }
        if (! in_array($input['kind'], self::KINDS, true)) {
            $errors['kind'] = ['Choose the kind of room.'];
        }
        $same = DB::table('lecture_rooms')->where('division_id', $divisionId)->where('code', $code);
        if ($input['id'] !== null) {
            Where::owned('lecture_rooms', $input['id'], $divisionId);
            $same->where('id', '<>', $input['id']);
        }
        if ($same->exists()) {
            $errors['code'] = ['Another room has this code.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $id = $input['id'] ?? Ids::next();
        $now = $this->clock->now();
        $row = ['code' => $code, 'name' => $name, 'seats' => $input['seats'], 'kind' => $input['kind'], 'retired_at' => $input['retired'] ? $now : null, 'updated_at' => $now];

        DB::transaction(function () use ($by, $divisionId, $input, $id, $row, $now): void {
            if ($input['id'] === null) {
                DB::table('lecture_rooms')->insert([...$row, 'id' => $id, 'division_id' => $divisionId, 'created_at' => $now]);
            } else {
                DB::table('lecture_rooms')->where('id', $id)->update($row);
            }
            $this->audit->record(LecturesAudit::RoomSaved, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId), meta: ['room' => $id]);
        });

        return $id;
    }

    /**
     * Replaces the division's campus areas, and sets how long scanning stays open.
     *
     * @param  list<array{name: string, latitude: float, longitude: float, reach: int}>  $areas
     */
    public function saveAreas(User $by, string $divisionId, array $areas, int $minutes): void
    {
        $this->where->division($by, $divisionId, LecturesPermission::Timetable);
        $errors = [];
        if ($minutes < 10 || $minutes > 30) {
            $errors['scan_minutes'] = ['Scanning stays open from 10 to 30 minutes.'];
        }
        if (count($areas) > 10) {
            $errors['areas'] = ['Give up to 10 areas.'];
        }
        foreach ($areas as $i => $a) {
            if (trim($a['name']) === '' || mb_strlen(trim($a['name'])) > 80) {
                $errors["areas.{$i}.name"] = ['Name the area.'];
            }
            if ($a['latitude'] < -90 || $a['latitude'] > 90 || $a['longitude'] < -180 || $a['longitude'] > 180) {
                $errors["areas.{$i}.latitude"] = ['Give the centre as latitude and longitude.'];
            }
            if ($a['reach'] < 50 || $a['reach'] > 5000) {
                $errors["areas.{$i}.reach"] = ['The reach is from 50 to 5,000 metres.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $now = $this->clock->now();

        DB::transaction(function () use ($by, $divisionId, $areas, $minutes, $now): void {
            DB::table('lecture_areas')->where('division_id', $divisionId)->whereNull('retired_at')->update(['retired_at' => $now, 'updated_at' => $now]);
            foreach ($areas as $a) {
                DB::table('lecture_areas')->insert(['id' => Ids::next(), 'division_id' => $divisionId, 'name' => trim($a['name']), 'latitude' => round($a['latitude'], 6),
                    'longitude' => round($a['longitude'], 6), 'reach' => $a['reach'], 'created_at' => $now, 'updated_at' => $now]);
            }
            DB::table('lecture_settings')->upsert([['division_id' => $divisionId, 'scan_minutes' => $minutes, 'updated_by' => $by->id, 'updated_at' => $now]],
                ['division_id'], ['scan_minutes', 'updated_by', 'updated_at']);
            $this->audit->record(LecturesAudit::AreaSaved, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId),
                meta: ['areas' => count($areas), 'minutes' => $minutes]);
        });
    }

    /** How long scanning stays open at the division's lectures, in minutes. */
    public static function minutes(string $divisionId): int
    {
        $m = DB::table('lecture_settings')->where('division_id', $divisionId)->value('scan_minutes');

        return is_numeric($m) ? (int) $m : self::DEFAULT_MINUTES;
    }
}
