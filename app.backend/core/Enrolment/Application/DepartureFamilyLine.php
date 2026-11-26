<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Enrolment\Contracts\FamilyLine;
use Illuminate\Support\Facades\DB;

/** The line on a child's card in the portal once the child has left the school (D-114): the last day. */
final class DepartureFamilyLine implements FamilyLine
{
    public function __construct(private readonly LearnerPlaces $places) {}

    public function line(string $learnerId, ?string $classId): ?array
    {
        $place = $this->places->current($learnerId);

        if ($place === null || $place['open']) {
            return null;
        }

        $last = DB::table('learner_departures')->where('placement_id', $place['id'])->max('last_day');

        return is_string($last) ? ['key' => 'left', 'word' => 'left', 'label' => substr($last, 0, 10), 'tone' => 'plain'] : null;
    }
}
