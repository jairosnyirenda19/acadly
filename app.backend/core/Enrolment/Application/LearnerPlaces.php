<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * Where a learner is, for access (09 §6): staff reach a learner through the
 * class of the learner's current placement. A learner with no open
 * placement is reached at their last class.
 */
final class LearnerPlaces
{
    /** @return array{id: string, enrolment_id: string, class_id: string, year_id: string, division_id: string, campus_id: string, level_code: string, starts_on: string, open: bool}|null */
    public function current(string $learnerId): ?array
    {
        if (! Ids::isValid($learnerId)) {
            return null;
        }

        $row = DB::table('placements')->where('learner_id', $learnerId)
            ->orderByRaw('ends_on is not null')->orderByDesc('starts_on')->orderByDesc('created_at')
            ->first(['id', 'enrolment_id', 'class_id', 'year_id', 'division_id', 'campus_id', 'level_code', 'starts_on', 'ends_on']);

        if ($row === null) {
            return null;
        }

        return [
            'id' => Row::text($row, 'id'), 'enrolment_id' => Row::text($row, 'enrolment_id'), 'class_id' => Row::text($row, 'class_id'),
            'year_id' => Row::text($row, 'year_id'), 'division_id' => Row::text($row, 'division_id'), 'campus_id' => Row::text($row, 'campus_id'),
            'level_code' => Row::text($row, 'level_code'), 'starts_on' => substr(Row::text($row, 'starts_on'), 0, 10),
            'open' => Row::maybeText($row, 'ends_on') === null,
        ];
    }

    public function scopeOf(string $learnerId): ?ScopeRef
    {
        $place = $this->current($learnerId);

        return $place === null ? null : ScopeRef::of(ScopeType::ClassGroup, $place['class_id']);
    }
}
