<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Http;

use Acadly\Core\Enrolment\Contracts\Guardianship;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Attendance\Application\Actions\GiveAbsenceReason;
use Acadly\Modules\Attendance\Application\LearnerAttendance;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The parent portal's attendance screen (D-082), and the reason for an absence (D-084). */
final class FamilyAttendanceController
{
    /**
     * A child's attendance this term.
     *
     * The mornings of the term and the totals, for a parent whose link to the
     * child is active.
     *
     * @response array{child: array{id: string, first_name: string, name: string, class_id: string|null, class: string}, attendance: array{term: array{name: string, starts_on: string, ends_on: string}|null, today: string, totals: array{marked: int, present: int, absent: int, late: int, share: int|null, last_absent: string|null}, weeks: list<array{week: string, days: list<array{day: string, mark: string|null, school_day: bool, future: bool}>}>, absences: list<array{day: string, open: bool, reason: array{kind: 'sick'|'family'|'travel'|'other', note: string|null, by: string, by_me: bool, at: string}|null}>}|null}
     */
    public function show(Request $request, string $id, Guardianship $guardianship, LearnerAttendance $attendance): JsonResponse
    {
        $parent = self::parent($request);
        $child = $guardianship->child($parent, $id) ?? throw new NotFoundHttpException;

        return response()->json(['child' => $child, 'attendance' => $attendance->forFamily($id, $parent)]);
    }

    /**
     * Give the reason for an absence.
     *
     * The reason for a morning absence of the child, up to 14 days after it.
     * The register keeps the mark; staff see the reason beside it. A later
     * reason replaces what staff see, and every reason is kept.
     *
     * @response array{child: array{id: string, first_name: string, name: string, class_id: string|null, class: string}, attendance: array{term: array{name: string, starts_on: string, ends_on: string}|null, today: string, totals: array{marked: int, present: int, absent: int, late: int, share: int|null, last_absent: string|null}, weeks: list<array{week: string, days: list<array{day: string, mark: string|null, school_day: bool, future: bool}>}>, absences: list<array{day: string, open: bool, reason: array{kind: 'sick'|'family'|'travel'|'other', note: string|null, by: string, by_me: bool, at: string}|null}>}|null}
     */
    public function reason(Request $request, string $id, string $day, GiveAbsenceReason $give, Guardianship $guardianship, LearnerAttendance $attendance): JsonResponse
    {
        $parent = self::parent($request);
        $kind = $request->input('kind');
        $note = $request->input('note');
        $give->give($parent, $id, $day, is_string($kind) ? $kind : '', is_string($note) ? $note : null);

        return $this->show($request, $id, $guardianship, $attendance);
    }

    private static function parent(Request $request): User
    {
        $parent = $request->user();

        if (! $parent instanceof User) {
            throw new AuthenticationException;
        }

        return $parent;
    }
}
