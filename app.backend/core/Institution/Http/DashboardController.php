<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Http;

use Acadly\Core\Institution\Application\Dashboards;
use Acadly\Kernel\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The dashboards and Today (D-100). */
final class DashboardController
{
    /**
     * The dashboard of the place worked in: in the school view the whole-school
     * overview, inside a division that division's own. Its views, and the panels
     * of the view asked for (the first view when none is asked for).
     *
     * @response array{school: bool, views: list<array{key: string, label: string}>, view: string|null, panels: list<array{id: string, view: string, order: int, width: 'full'|'half'|'quarter', kind: string, label: string, title: string, finding: string|null, data: array<string, mixed>}>}
     */
    public function dashboard(Request $request, Dashboards $dashboards): JsonResponse
    {
        $request->validate(['view' => ['nullable', 'string', 'max:20']]);
        $view = $request->query('view');

        return response()->json($dashboards->dashboard(self::user($request), is_string($view) ? $view : null));
    }

    /**
     * The person's Today, in the place worked in: what is happening now, one
     * small chart of their week, and what they must do.
     *
     * @response array{now: list<array{key: string, order: int, label: string, title: string, sub: string, state: array{tone: 'plain'|'done'|'attention'|'wrong', text: string}|null, action: string, href: string}>, chart: array{kind: 'columns'|'segments', label: string, unit: string, labels: list<string>, values: list<float|int|null>, parts: list<array{name: string, value: int, tone: 'plain'|'done'|'attention'|'wrong'}>}|null, items: list<array{key: string, order: int, title: string, sub: string, count: string|null, tone: 'plain'|'done'|'attention'|'wrong', href: string|null}>}
     */
    public function today(Request $request, Dashboards $dashboards): JsonResponse
    {
        return response()->json($dashboards->today(self::user($request)));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
