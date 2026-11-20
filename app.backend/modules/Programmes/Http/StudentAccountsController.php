<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Application\Actions\StudentAccountActions;
use Acadly\Modules\Programmes\Application\Place;
use Acadly\Modules\Programmes\Application\StudentAccountDirectory;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The registry's student accounts (D-101). */
final class StudentAccountsController
{
    /**
     * Every active student of the division with the state of their account.
     *
     * @response array{totals: array{students: int, active: int, code_sent: int, none: int, suspended: int}, rows: list<array{learner_id: string, number: string, name: string, programme: string, year: int, phone: string|null, state: 'active'|'code_sent'|'none'|'suspended', since: string|null, reason: string|null}>}
     */
    public function index(Request $request, string $division, Place $place): JsonResponse
    {
        $place->division(self::user($request), $division, ProgrammesPermission::Accounts);
        $rows = [];
        $totals = ['students' => 0, 'active' => 0, 'code_sent' => 0, 'none' => 0, 'suspended' => 0];

        foreach (DB::table('programme_students as s')->join('learners as l', 'l.id', '=', 's.learner_id')->join('programmes as p', 'p.id', '=', 's.programme_id')
            ->leftJoin('programme_student_accounts as a', 'a.learner_id', '=', 's.learner_id')
            ->where('s.division_id', $division)->whereNull('s.ended_at')->where('s.status', 'active')->orderBy('p.code')->orderBy('s.year_of_study')->orderBy('l.surname')->orderBy('l.first_name')
            ->get(['s.learner_id', 'l.first_name', 'l.surname', 'p.code', 's.year_of_study', 'a.phone', 'a.user_id', 'a.code_sent_at', 'a.set_up_at', 'a.suspended_at', 'a.suspended_reason']) as $r) {
            $state = Row::maybeText($r, 'suspended_at') !== null ? 'suspended' : (Row::maybeText($r, 'set_up_at') !== null ? 'active' : (Row::maybeText($r, 'code_sent_at') !== null ? 'code_sent' : 'none'));
            $phone = Row::maybeText($r, 'phone');
            $rows[] = ['learner_id' => Row::text($r, 'learner_id'), 'number' => StudentAccountDirectory::numberOf(Row::text($r, 'learner_id')),
                'name' => Row::text($r, 'first_name').' '.Row::text($r, 'surname'), 'programme' => Row::text($r, 'code'), 'year' => Row::int($r, 'year_of_study'),
                'phone' => $phone === null ? null : '•• '.substr($phone, -2), 'state' => $state,
                'since' => match ($state) {
                    'suspended' => Row::maybeText($r, 'suspended_at'), 'active' => Row::maybeText($r, 'set_up_at'), 'code_sent' => Row::maybeText($r, 'code_sent_at'), default => null
                },
                'reason' => $state === 'suspended' ? Row::maybeText($r, 'suspended_reason') : null];
            $totals['students']++;
            $totals[$state]++;
        }

        return response()->json(['totals' => $totals, 'rows' => $rows]);
    }

    /** @response array{saved: bool} */
    public function phone(Request $request, string $division, string $learner, StudentAccountActions $actions, Place $place): JsonResponse
    {
        $place->division(self::user($request), $division, ProgrammesPermission::Accounts);
        $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $actions->setPhone(self::user($request), $division, $learner, $request->string('phone')->toString());

        return response()->json(['saved' => true]);
    }

    /**
     * Sends set-up codes to students: one, several, or every student who has no account yet.
     *
     * @response array{sent: int, no_phone: int}
     */
    public function codes(Request $request, string $division, StudentAccountActions $actions, Place $place): JsonResponse
    {
        $place->division(self::user($request), $division, ProgrammesPermission::Accounts);
        $request->validate(['learners' => ['present', 'array', 'max:500'], 'learners.*' => ['uuid']]);
        $ids = array_values(array_filter((array) $request->input('learners'), 'is_string'));

        return response()->json($actions->sendCodes(self::user($request), $division, $ids));
    }

    /** @response array{saved: bool} */
    public function suspend(Request $request, string $division, string $learner, StudentAccountActions $actions, Place $place): JsonResponse
    {
        $place->division(self::user($request), $division, ProgrammesPermission::Accounts);
        $request->validate(['reason' => ['required', 'string', 'max:255'], 'restore' => ['sometimes', 'boolean']]);
        $request->boolean('restore')
            ? $actions->restore(self::user($request), $division, $learner, $request->string('reason')->toString())
            : $actions->suspend(self::user($request), $division, $learner, $request->string('reason')->toString());

        return response()->json(['saved' => true]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
