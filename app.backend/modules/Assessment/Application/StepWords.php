<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Contracts\ObservationStep;
use Illuminate\Support\Facades\DB;

/**
 * The school's words for the three review steps (D-108), in English and in
 * Chichewa. The strokes never change, so the meaning is kept; "Not seen" is
 * no step and keeps its word. Each save is a new row: the latest is in force.
 *
 * @phpstan-type Words array{starting: string, growing: string, can_do: string}
 */
final class StepWords
{
    /** @return array{en: Words, ny: Words, saved_at: string|null, saved_by: string|null} */
    public static function current(): array
    {
        $row = DB::table('observation_step_words as w')->leftJoin('users as u', 'u.id', '=', 'w.saved_by')->orderByDesc('w.saved_at')->first(['w.*', 'u.name']);
        $en = ['starting' => ObservationStep::Starting->label(), 'growing' => ObservationStep::Growing->label(), 'can_do' => ObservationStep::CanDo->label()];
        $ny = ['starting' => '', 'growing' => '', 'can_do' => ''];
        if ($row === null) {
            return ['en' => $en, 'ny' => $ny, 'saved_at' => null, 'saved_by' => null];
        }

        return ['en' => ['starting' => Row::text($row, 'starting'), 'growing' => Row::text($row, 'growing'), 'can_do' => Row::text($row, 'can_do')],
            'ny' => ['starting' => Row::maybeText($row, 'starting_ny') ?? '', 'growing' => Row::maybeText($row, 'growing_ny') ?? '', 'can_do' => Row::maybeText($row, 'can_do_ny') ?? ''],
            'saved_at' => Row::text($row, 'saved_at'), 'saved_by' => Row::maybeText($row, 'name')];
    }

    /** The school's Chichewa word for a step, or '' when the school has not given one. */
    public static function chichewa(ObservationStep $step): string
    {
        if ($step === ObservationStep::NotSeen) {
            return '';
        }
        $words = self::current()['ny'];

        return match ($step) {
            ObservationStep::Starting => $words['starting'],
            ObservationStep::Growing => $words['growing'],
            default => $words['can_do'],
        };
    }

    /** The word the school uses for a step. */
    public static function label(ObservationStep $step): string
    {
        if ($step === ObservationStep::NotSeen) {
            return $step->label();
        }
        $words = self::current()['en'];

        return match ($step) {
            ObservationStep::Starting => $words['starting'],
            ObservationStep::Growing => $words['growing'],
            default => $words['can_do'],
        };
    }
}
