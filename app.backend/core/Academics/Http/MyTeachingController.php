<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Http;

use Acadly\Core\Academics\Application\Calendar;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** What you teach this year, for Your account (D-089). Given by the school office; read-only here. */
final class MyTeachingController
{
    /**
     * What you teach.
     *
     * The classes you are class teacher of, and each subject you teach with its classes, this school year.
     *
     * @response array{class_teacher: list<string>, subjects: list<array{subject: string, classes: list<string>}>}
     */
    public function __invoke(Request $request, Calendar $calendar): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $year = $calendar->currentYear();
        $rows = $year === null ? [] : DB::table('academic_teaching as t')->join('academic_classes as c', 'c.id', '=', 't.class_id')
            ->leftJoin('academic_subjects as s', 's.id', '=', 't.subject_id')
            ->where('t.user_id', $user->id)->whereNull('t.ended_at')->where('c.year_id', $year['id'])->whereNull('c.ended_at')
            ->orderBy('c.position')->get(['c.name as class', 's.name as subject'])->all();
        $classTeacher = [];
        $subjects = [];

        foreach ($rows as $r) {
            $subject = Row::maybeText($r, 'subject');

            if ($subject === null) {
                $classTeacher[] = Row::text($r, 'class');
            } else {
                $subjects[$subject][] = Row::text($r, 'class');
            }
        }

        ksort($subjects);

        return response()->json(['class_teacher' => $classTeacher,
            'subjects' => array_map(fn (string $s, array $c): array => ['subject' => $s, 'classes' => array_values(array_unique($c))], array_keys($subjects), $subjects)]);
    }
}
