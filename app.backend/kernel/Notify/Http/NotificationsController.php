<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Actions\MarkNotificationRead;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class NotificationsController
{
    /**
     * The signed-in person's in-app notices, newest first.
     *
     * @response array{unread: int, items: list<array{id: string, topic: string, subject: string, body: string, action_url: string|null, created_at: string, read_at: string|null}>}
     */
    public function index(Request $request): JsonResponse
    {
        $user = self::user($request);
        $mine = DB::table('in_app_notifications')->where('user_id', $user->id);

        $items = (clone $mine)->orderByDesc('created_at')->limit(50)
            ->get(['id', 'topic_key', 'subject', 'body', 'action_url', 'created_at', 'read_at'])
            ->map(fn (object $row): array => [
                'id' => self::text($row, 'id'),
                'topic' => self::text($row, 'topic_key'),
                'subject' => self::text($row, 'subject'),
                'body' => self::text($row, 'body'),
                'action_url' => self::maybe($row, 'action_url'),
                'created_at' => self::text($row, 'created_at'),
                'read_at' => self::maybe($row, 'read_at'),
            ])
            ->values()
            ->all();

        return response()->json(['unread' => (clone $mine)->whereNull('read_at')->count(), 'items' => $items]);
    }

    /** Marks one of the person's own notices as read. */
    public function read(Request $request, string $id, MarkNotificationRead $action): Response
    {
        if (! $action->handle(self::user($request), $id)) {
            throw new NotFoundHttpException;
        }

        return response()->noContent();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }

    private static function text(object $row, string $column): string
    {
        $value = $row->{$column} ?? null;

        return is_string($value) ? $value : '';
    }

    private static function maybe(object $row, string $column): ?string
    {
        $value = $row->{$column} ?? null;

        return is_string($value) ? $value : null;
    }
}
