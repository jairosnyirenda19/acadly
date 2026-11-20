<?php

declare(strict_types=1);

namespace Acadly\Modules\News\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Boundary;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\News\Application\Actions\NewsActions;
use Acadly\Modules\News\Application\NewsBoard;
use Acadly\Modules\News\Contracts\NewsPermission;
use Acadly\Modules\Programmes\Contracts\StudentPlaces;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** News (D-101): staff read and publish in the console; students read in the student portal. */
final class NewsController
{
    /**
     * The news of a division: for those who publish, every post with how many have seen it, and the audiences to choose from;
     * for other staff, the news for everyone and for staff.
     *
     * @response array{can_publish: bool, posts: list<array{id: string, title: string, body: string, audience: string, audience_id: string|null, year: int|null, audience_label: string, pinned: bool, pinned_until: string|null, publish_at: string, scheduled: bool, updated: bool, author: string, read: bool, seen: int|null, reach: int|null}>, audiences: array{faculties: list<array{id: string, name: string}>, departments: list<array{id: string, name: string}>, programmes: list<array{id: string, name: string}>, years: list<int>}|null}
     */
    public function index(Request $request, string $division, Access $access, Boundary $boundary, NewsBoard $board, NewsActions $actions): JsonResponse
    {
        $viewer = self::user($request);
        $at = ScopeRef::of(ScopeType::Division, $division);
        $publishes = $access->allows($viewer, NewsPermission::Publish, $at);
        if (! $publishes && ($boundary->refuses($viewer, $at, 'news') || $access->grants($viewer) === [])) {
            throw new NotFoundHttpException;
        }
        $places = app()->bound(StudentPlaces::class) ? app(StudentPlaces::class) : null;
        $posts = [];
        foreach ($board->forStaff($viewer->id, $division, $publishes) as $p) {
            $actions->read($p['id'], $viewer->id);
            $posts[] = [...$p, 'seen' => $publishes ? $board->seen($p['id']) : null,
                'reach' => $publishes && $places !== null && $p['audience'] !== 'staff' ? $places->count($division, $p['audience'], $p['audience_id'], $p['year']) : null];
        }

        return response()->json(['can_publish' => $publishes, 'posts' => $posts, 'audiences' => $publishes && $places !== null ? $places->structure($division) : null]);
    }

    /** @response 201 array{id: string} */
    public function store(Request $request, string $division, NewsActions $actions, Access $access): JsonResponse
    {
        $access->check(self::user($request), NewsPermission::Publish, ScopeRef::of(ScopeType::Division, $division));

        return response()->json(['id' => $actions->publish(self::user($request), $division, self::input($request))], 201);
    }

    /** @response array{saved: bool} */
    public function update(Request $request, string $id, NewsActions $actions): JsonResponse
    {
        $actions->edit(self::user($request), $id, self::input($request));

        return response()->json(['saved' => true]);
    }

    /** @response array{saved: bool} */
    public function withdraw(Request $request, string $id, NewsActions $actions): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $actions->withdraw(self::user($request), $id, $request->string('reason')->toString());

        return response()->json(['saved' => true]);
    }

    /**
     * The student's news, pinned first.
     *
     * @response array{posts: list<array{id: string, title: string, body: string, audience: string, audience_label: string, pinned: bool, publish_at: string, updated: bool, author: string, read: bool}>}
     */
    public function student(Request $request, NewsBoard $board): JsonResponse
    {
        return response()->json(['posts' => $board->forStudent(self::user($request)->id) ?? throw new NotFoundHttpException]);
    }

    /**
     * One post for the student, marked read.
     *
     * @response array{post: array{id: string, title: string, body: string, audience: string, audience_label: string, pinned: bool, publish_at: string, updated: bool, author: string, read: bool}}
     */
    public function studentPost(Request $request, string $id, NewsBoard $board, NewsActions $actions): JsonResponse
    {
        $user = self::user($request);
        $post = $board->studentPost($user->id, $id) ?? throw new NotFoundHttpException;
        $actions->read($id, $user->id);

        return response()->json(['post' => [...$post, 'read' => true]]);
    }

    /** @return array{title: string, body: string, audience: string, audience_id: string|null, year: int|null, pinned_until: string|null, publish_at: string|null} */
    private static function input(Request $request): array
    {
        $request->validate(['title' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:10000'], 'audience' => ['required', 'string', 'max:16'],
            'audience_id' => ['nullable', 'uuid'], 'year' => ['nullable', 'integer', 'min:1', 'max:10'], 'pinned_until' => ['nullable', 'date_format:Y-m-d'], 'publish_at' => ['nullable', 'date']]);
        $str = fn (string $k): ?string => is_string($v = $request->input($k)) && $v !== '' ? $v : null;

        return ['title' => $request->string('title')->toString(), 'body' => $request->string('body')->toString(), 'audience' => $request->string('audience')->toString(),
            'audience_id' => $str('audience_id'), 'year' => $request->filled('year') ? $request->integer('year') : null, 'pinned_until' => $str('pinned_until'), 'publish_at' => $str('publish_at')];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
