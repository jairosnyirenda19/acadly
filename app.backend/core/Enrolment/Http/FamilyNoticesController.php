<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Http;

use Acadly\Core\Enrolment\Application\Actions\FamilyNoticeActions;
use Acadly\Core\Enrolment\Application\FamilyNotices;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Notices to families (D-115): the office writes them; families read them in the portal. */
final class FamilyNoticesController
{
    /**
     * The notices in the place worked in, newest first, with how many families read each and the texts delivered.
     *
     * @response array{notices: list<array{id: string, title: string, for: string, publish_at: string, scheduled: bool, author: string, read: int, families: int, texts: array{all: int, delivered: int, not_delivered: int}|null, withdrawn: array{reason: string, at: string}|null}>, text_room: int, choices: array{school: bool, divisions: list<array{id: string, name: string, whole: bool, levels: list<array{code: string, label: string}>, classes: list<array{id: string, name: string}>}>}}
     */
    public function index(Request $request, FamilyNotices $notices, FamilyNoticeActions $actions, Access $access): JsonResponse
    {
        $who = self::user($request);
        $access->check($who, EnrolmentPermission::Notify);

        return response()->json(['notices' => $notices->forStaff($who), 'text_room' => $actions->textRoom(), 'choices' => $notices->choices($who)]);
    }

    /**
     * Who a notice would reach: learners, families, families in the portal, and the texts it would send.
     *
     * @response array{learners: int, families: int, in_portal: int, texts: int}
     */
    public function reach(Request $request, FamilyNotices $notices, Access $access): JsonResponse
    {
        $who = self::user($request);
        $request->validate(['audience' => ['nullable', 'string', 'max:10'], 'division_id' => ['nullable', 'uuid'], 'level_code' => ['nullable', 'string', 'max:16'], 'class_id' => ['nullable', 'uuid']]);
        // No audience is the whole school, so the place is checked before anything else.
        $a = ['audience' => self::maybe($request, 'audience') ?? 'school', 'division_id' => self::maybe($request, 'division_id'), 'level_code' => self::maybe($request, 'level_code'), 'class_id' => self::maybe($request, 'class_id')];
        $access->check($who, EnrolmentPermission::Notify, FamilyNotices::scopeOf($a['audience'], $a['division_id'], $a['class_id']));

        return response()->json($notices->reach($a));
    }

    /**
     * Publishes a notice; with a text message when asked for, sent now.
     *
     * @response 201 array{id: string}
     */
    public function store(Request $request, FamilyNoticeActions $actions, Access $access): JsonResponse
    {
        // The place first: no audience is the whole school.
        $request->validate(['audience' => ['nullable', 'string', 'max:10'], 'division_id' => ['nullable', 'uuid'], 'class_id' => ['nullable', 'uuid']]);
        $access->check(self::user($request), EnrolmentPermission::Notify,
            FamilyNotices::scopeOf(self::maybe($request, 'audience') ?? 'school', self::maybe($request, 'division_id'), self::maybe($request, 'class_id')));
        $request->validate(['audience' => ['required', 'string', 'max:10'], 'division_id' => ['nullable', 'uuid'], 'level_code' => ['nullable', 'string', 'max:16'], 'class_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:4000'], 'sms_text' => ['nullable', 'string', 'max:160'],
            'pinned_until' => ['nullable', 'date_format:Y-m-d'], 'publish_at' => ['nullable', 'date']]);

        return response()->json(['id' => $actions->publish(self::user($request), [
            'audience' => self::text($request, 'audience'), 'division_id' => self::maybe($request, 'division_id'), 'level_code' => self::maybe($request, 'level_code'),
            'class_id' => self::maybe($request, 'class_id'), 'title' => self::text($request, 'title'), 'body' => self::text($request, 'body'),
            'sms_text' => self::maybe($request, 'sms_text'), 'pinned_until' => self::maybe($request, 'pinned_until'), 'publish_at' => self::maybe($request, 'publish_at'),
        ])], 201);
    }

    /**
     * Withdraws a notice, with a reason. It leaves the portal at once; a text already sent stays sent.
     *
     * @response array{id: string}
     */
    public function withdraw(Request $request, string $id, FamilyNoticeActions $actions): JsonResponse
    {
        $actions->withdraw(self::user($request), $id, self::text($request, 'reason'));

        return response()->json(['id' => $id]);
    }

    /**
     * The notices for a parent's children, pinned first.
     *
     * @response array{notices: list<array{id: string, title: string, body: string, for: string, publish_at: string, pinned: bool, new: bool, from: string}>}
     */
    public function family(Request $request, FamilyNotices $notices): JsonResponse
    {
        return response()->json(['notices' => $notices->forParent(self::user($request))]);
    }

    /**
     * One notice, opened by a parent: it now counts as read.
     *
     * @response array{id: string, title: string, body: string, for: string, publish_at: string, pinned: bool, new: bool, from: string}
     */
    public function open(Request $request, string $id, FamilyNotices $notices): JsonResponse
    {
        return response()->json($notices->open(self::user($request), $id));
    }

    private static function text(Request $request, string $key): string
    {
        return $request->string($key)->trim()->toString();
    }

    private static function maybe(Request $request, string $key): ?string
    {
        $v = self::text($request, $key);

        return $v === '' ? null : $v;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
