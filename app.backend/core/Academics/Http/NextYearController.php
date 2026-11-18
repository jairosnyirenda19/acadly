<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Http;

use Acadly\Core\Academics\Application\Actions\SaveNextYear;
use Acadly\Core\Academics\Application\NextYear;
use Acadly\Core\Academics\Contracts\AcademicsPermission;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The school year after this one (D-088). */
final class NextYearController
{
    /**
     * The next school year.
     *
     * This year, and the next as saved, or as proposed from this one: its
     * dates, the terms of each level, and the classes of each level at each
     * campus, beside this year's.
     *
     * @response array{current: array{id: string, name: string, starts_on: string, ends_on: string}|null, next: array{saved: bool, id: string|null, name: string, starts_on: string, ends_on: string, terms: list<array{division_id: string, division: string, terms: list<array{name: string, starts_on: string, ends_on: string}>}>, classes: list<array{division_id: string, division: string, campus_id: string, campus: string, levels: list<array{code: string, label: string, now: list<string>, next: list<string>}>}>}|null}
     */
    public function show(Request $request, NextYear $next, Access $access): JsonResponse
    {
        $access->check(self::user($request), AcademicsPermission::Manage);

        return response()->json($next->show());
    }

    /**
     * Save the next school year.
     *
     * `{name, starts_on, ends_on, terms: [{division_id, terms: [{name, starts_on, ends_on}]}], classes: [{division_id, campus_id, streams: {LEVEL: [names]}}]}`.
     * It may be saved again until it starts. The response is the same as GET.
     */
    public function save(Request $request, SaveNextYear $save, NextYear $next, Access $access): JsonResponse
    {
        $save->handle(self::user($request), $request->all());

        return $this->show($request, $next, $access);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
