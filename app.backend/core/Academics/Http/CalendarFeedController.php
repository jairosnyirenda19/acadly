<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Http;

use Acadly\Core\Academics\Application\CalendarFeed;
use Acadly\Kernel\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Your lessons in your calendar (D-089). */
final class CalendarFeedController
{
    /**
     * Whether your lessons feed is on.
     *
     * @response array{on: bool, since: string|null, last_used_at: string|null}
     */
    public function show(Request $request, CalendarFeed $feed): JsonResponse
    {
        return response()->json($feed->state(self::user($request)));
    }

    /**
     * Turns the feed on, or makes a new address: the old one stops working. The address is shown once.
     *
     * @response array{url: string}
     */
    public function on(Request $request, CalendarFeed $feed): JsonResponse
    {
        return response()->json(['url' => $feed->on(self::user($request))]);
    }

    /**
     * Turns the feed off.
     *
     * @response array{on: bool, since: string|null, last_used_at: string|null}
     */
    public function off(Request $request, CalendarFeed $feed): JsonResponse
    {
        $feed->off(self::user($request));

        return $this->show($request, $feed);
    }

    /** The feed: no sign-in; the private address is the key. */
    public function ics(string $token, CalendarFeed $feed): Response
    {
        $ics = $feed->ics($token) ?? throw new NotFoundHttpException;

        return response($ics, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Cache-Control' => 'private, max-age=900']);
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
