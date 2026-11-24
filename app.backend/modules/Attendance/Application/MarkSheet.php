<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Modules\Attendance\Contracts\Mark;
use Illuminate\Validation\ValidationException;

/** Checks the marks sent with a register: every learner expected, marked once, with a known mark. */
final class MarkSheet
{
    /**
     * @param  array<string, string>  $expected  learner id => name
     * @param  list<array{learner_id: string, status: string, note: string|null}>  $marks
     * @param  array<string, string>  $decided  learner id => the decision that keeps them out that day (D-111)
     * @return array<string, array{status: string, note: string|null}> learner id => mark
     */
    public static function check(array $expected, array $marks, array $decided = []): array
    {
        $given = [];

        foreach ($marks as $i => $mark) {
            if (! isset($expected[$mark['learner_id']])) {
                throw ValidationException::withMessages(["marks.{$i}" => ['This learner is not in the class on this day.']]);
            }

            if (isset($given[$mark['learner_id']])) {
                throw ValidationException::withMessages(["marks.{$i}" => ['This learner is marked twice.']]);
            }

            if (Mark::tryFrom($mark['status']) === null) {
                throw ValidationException::withMessages(["marks.{$i}" => ['Choose present, absent, late or excused.']]);
            }

            $note = trim((string) $mark['note']);

            if (mb_strlen($note) > 255) {
                throw ValidationException::withMessages(["marks.{$i}" => ['A reason can be up to 255 characters.']]);
            }

            $given[$mark['learner_id']] = ['status' => $mark['status'], 'note' => $note !== '' ? $note : null];
        }

        // A decision sets the mark: Excused, naming it. The teacher cannot change it.
        foreach ($decided as $learner => $note) {
            if (isset($expected[$learner])) {
                $given[$learner] = ['status' => Mark::Excused->value, 'note' => $note];
            }
        }

        $missing = count(array_diff_key($expected, $given));

        if ($missing > 0) {
            throw ValidationException::withMessages(['marks' => [($missing === 1 ? '1 learner is' : "{$missing} learners are").' not marked. Mark every learner, then submit.']]);
        }

        return $given;
    }
}
