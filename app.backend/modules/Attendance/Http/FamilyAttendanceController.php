<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Http;

use Acadly\Core\Enrolment\Contracts\Guardianship;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Attendance\Application\LearnerAttendance;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The parent portal's attendance screen (D-082). */
final class FamilyAttendanceController
{
    /**
     * A child's attendance this term.
     *
     * The mornings of the term and the totals, for a parent whose link to the
     * child is active.
     *
     * @response array{child: array{id: string, first_name: string, name: string, class_id: string|null, class: string}, attendance: array{term: array{name: string, starts_on: string, ends_on: string}|null, today: string, totals: array{marked: int, present: int, absent: int, late: int, share: int|null, last_absent: string|null}, weeks: list<array{week: string, days: list<array{day: string, mark: string|null, school_day: bool, future: bool}>}>}|null}
     */
    public function __invoke(Request $request, string $id, Guardianship $guardianship, LearnerAttendance $attendance): JsonResponse
    {
        $parent = $request->user();

        if (! $parent instanceof User) {
            throw new AuthenticationException;
        }

        $child = $guardianship->child($parent, $id) ?? throw new NotFoundHttpException;

        return response()->json(['child' => $child, 'attendance' => $attendance->forFamily($id)]);
    }
}
