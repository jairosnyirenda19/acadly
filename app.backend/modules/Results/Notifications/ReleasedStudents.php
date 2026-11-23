<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Notifications;

use Acadly\Kernel\Notify\PartyResolver;
use Acadly\Kernel\Notify\Recipient;
use Acadly\Kernel\Notify\Topic;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Illuminate\Support\Facades\DB;

/** The students of every sheet in a release, once each. */
final class ReleasedStudents implements PartyResolver
{
    public function __construct(private readonly CourseTeaching $teaching) {}

    public function resolve(Topic $topic): array
    {
        if (! $topic instanceof ResultsReleased) {
            return [];
        }
        $out = [];
        foreach (DB::table('results_sheets')->where('release_id', $topic->releaseId)->get(['id', 'offering_id']) as $sheet) {
            $sat = DB::table('results_lines')->where('sheet_id', Row::text($sheet, 'id'))->pluck('learner_id')->all();
            foreach ($this->teaching->students(Row::text($sheet, 'offering_id')) as $s) {
                if (in_array($s['learner_id'], $sat, true)) {
                    $out[$s['learner_id']] ??= new Recipient($s['name'], $s['user_id'], null, $s['phone']);
                }
            }
        }

        return array_values($out);
    }
}
