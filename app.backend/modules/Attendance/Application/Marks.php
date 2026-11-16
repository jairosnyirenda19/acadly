<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/** The marks in force: for each learner in a register, the latest mark written. */
final class Marks
{
    public function current(string $registerId, string $learnerId): ?string
    {
        $status = DB::table('attendance_marks')->where('register_id', $registerId)->where('learner_id', $learnerId)
            ->orderByDesc('received_at')->orderByDesc('id')->value('status');

        return is_string($status) ? $status : null;
    }

    /**
     * @param  list<string>  $registerIds
     * @return array<string, array<string, array{status: string, note: string|null, corrected: bool}>> register id => learner id => mark
     */
    public function inForce(array $registerIds): array
    {
        if ($registerIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('attendance_marks')->whereIn('register_id', $registerIds)->orderBy('received_at')->orderBy('id')
            ->get(['register_id', 'learner_id', 'status', 'note', 'correction_id']) as $row) {
            $out[Row::text($row, 'register_id')][Row::text($row, 'learner_id')] = [
                'status' => Row::text($row, 'status'),
                'note' => Row::maybeText($row, 'note'),
                'corrected' => Row::maybeText($row, 'correction_id') !== null,
            ];
        }

        return $out;
    }
}
