<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * The reasons families gave for morning absences (D-084). Staff and the
 * family see the latest reason of each absence; every reason is kept.
 */
final class AbsenceReasons
{
    public const string SICK = 'sick';

    public const string FAMILY = 'family';

    public const string TRAVEL = 'travel';

    public const string OTHER = 'other';

    public const array KINDS = [self::SICK, self::FAMILY, self::TRAVEL, self::OTHER];

    /** The words staff read in a correction's reason. */
    public const array LABELS = [self::SICK => 'Sick or seeing a doctor', self::FAMILY => 'Family matter', self::TRAVEL => 'Could not travel to school', self::OTHER => 'Another reason'];

    /** A family may give a reason up to this many days after the absence. */
    public const int OPEN_DAYS = 14;

    /**
     * The latest reason for each learner on these registers.
     *
     * @param  list<string>  $registerIds
     * @return array<string, array{kind: string, note: string|null, by: string, by_id: string, at: string}> keyed "register:learner"
     */
    public function latest(array $registerIds, ?string $learnerId = null): array
    {
        if ($registerIds === []) {
            return [];
        }

        $rows = DB::table('attendance_absence_reasons')->whereIn('register_id', $registerIds)
            ->when($learnerId !== null, fn ($q) => $q->where('learner_id', $learnerId))
            ->orderBy('given_at')->orderBy('id')->get();
        $names = DB::table('users')->whereIn('id', array_values(array_unique(array_map(fn (object $r): string => Row::text($r, 'given_by'), $rows->all()))) ?: [Lessons::NONE])
            ->pluck('name', 'id')->all();
        $out = [];

        foreach ($rows as $r) {
            $by = Row::text($r, 'given_by');
            $out[Row::text($r, 'register_id').':'.Row::text($r, 'learner_id')] = [
                'kind' => Row::text($r, 'kind'),
                'note' => Row::maybeText($r, 'note'),
                'by' => is_string($names[$by] ?? null) ? $names[$by] : '',
                'by_id' => $by,
                'at' => Row::text($r, 'given_at'),
            ];
        }

        return $out;
    }

    /**
     * The reason as staff see it, or null.
     *
     * @param  array<string, array{kind: string, note: string|null, by: string, by_id: string, at: string}>  $latest
     * @return array{kind: string, note: string|null, by: string, at: string}|null
     */
    public static function forStaff(array $latest, string $registerId, string $learnerId): ?array
    {
        $r = $latest[$registerId.':'.$learnerId] ?? null;

        return $r === null ? null : ['kind' => $r['kind'], 'note' => $r['note'], 'by' => $r['by'], 'at' => $r['at']];
    }
}
