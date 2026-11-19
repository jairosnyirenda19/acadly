<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Http;

use Acadly\Core\Enrolment\Contracts\Guardianship;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\SafeRelease\Application\Actions\PickupActions;
use Acadly\Modules\SafeRelease\Application\PickupReach;
use Acadly\Modules\SafeRelease\Application\PickupViews;
use Acadly\Modules\SafeRelease\Contracts\SafeReleasePermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Safe pick-up over the API (D-094). */
final class PickupController
{
    /**
     * The pick-up desk: the rooms the account sees and each child of the chosen room today.
     *
     * @response array{day: string, rooms: list<array{id: string, name: string}>, room: array{id: string, name: string}|null, children: list<array{id: string, name: string, collected: array{id: string, state: string, state_label: string, name: string, relationship: string|null, at: string, note: string|null}|null, refused: int}>, totals: array{children: int, collected: int, here: int}, can_release: bool}
     */
    public function desk(Request $request, PickupViews $views, Access $access): JsonResponse
    {
        $who = self::user($request);

        if (! self::holdsAny($access, $who)) {
            $access->check($who, SafeReleasePermission::View);
        }

        $room = $request->query('class_id');

        return response()->json($views->desk($who, is_string($room) && $room !== '' ? $room : null));
    }

    /**
     * One child: who may collect today, who must not, and the child's pick-ups.
     *
     * @response array{learner: array{id: string, name: string}, may_collect: list<array{kind: 'contact'|'collector', id: string, name: string, relationship: string, phone: string|null, day_only: bool, needs_code: bool, added_as: string}>, barred: list<array{id: string, contact_id: string, name: string, reason: string, since: string}>, contacts: list<array{id: string, name: string}>, today: list<array{id: string, state: string, state_label: string, name: string, relationship: string|null, at: string, note: string|null}>, recent: list<array{id: string, state: string, state_label: string, name: string, relationship: string|null, at: string, note: string|null, day: string}>, can: array{release: bool, manage: bool}}
     */
    public function child(Request $request, string $id, PickupViews $views): JsonResponse
    {
        return response()->json($views->child(self::user($request), $id));
    }

    /**
     * Releases a child to someone on the list.
     *
     * @response array{id: string}
     */
    public function release(Request $request, string $id, PickupActions $actions, PickupReach $reach): JsonResponse
    {
        $who = self::user($request);
        $reach->learner($who, $id, SafeReleasePermission::Release);
        $request->validate(['kind' => ['required', Rule::in(['contact', 'collector'])], 'collector_id' => ['required', 'uuid'], 'code' => ['nullable', 'string', 'max:10']]);
        $code = $request->string('code')->toString();

        return response()->json(['id' => $actions->release($who, $id, $request->string('kind')->toString(), $request->string('collector_id')->toString(), $code !== '' ? $code : null)->id]);
    }

    /**
     * Records that someone not on the list came, and the child was kept.
     *
     * @response array{id: string}
     */
    public function refuse(Request $request, string $id, PickupActions $actions, PickupReach $reach): JsonResponse
    {
        $who = self::user($request);
        $reach->learner($who, $id, SafeReleasePermission::Release);
        $request->validate(['name' => ['required', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['id' => $actions->refuse($who, $id, $request->string('name')->toString(), $request->string('note')->toString())->id]);
    }

    /**
     * Adds someone who may collect the child, for good or for today only.
     *
     * @response 201 array{id: string}
     */
    public function addCollector(Request $request, string $id, PickupActions $actions, PickupReach $reach): JsonResponse
    {
        $who = self::user($request);
        $reach->learner($who, $id, SafeReleasePermission::Manage);
        $request->validate(['name' => ['required', 'string', 'max:120'], 'relationship' => ['required', 'string', 'max:60'], 'phone' => ['nullable', 'string', 'max:30'], 'today_only' => ['sometimes', 'boolean']]);
        $phone = $request->string('phone')->toString();

        return response()->json(['id' => $actions->addCollector($who, $id, $request->string('name')->toString(), $request->string('relationship')->toString(),
            $phone !== '' ? $phone : null, $request->boolean('today_only'))], 201);
    }

    /** Ends someone's right to collect the child. */
    public function endCollector(Request $request, string $id, PickupActions $actions): JsonResponse
    {
        $actions->endCollector(self::user($request), $id);

        return response()->json(['ok' => true]);
    }

    /** Records that a contact must not collect the child. */
    public function bar(Request $request, string $id, PickupActions $actions, PickupReach $reach): JsonResponse
    {
        $who = self::user($request);
        $reach->learner($who, $id, SafeReleasePermission::Manage);
        $request->validate(['contact_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:2000']]);
        $actions->bar($who, $id, $request->string('contact_id')->toString(), $request->string('reason')->toString());

        return response()->json(['ok' => true]);
    }

    /** Ends a record that a contact must not collect the child. */
    public function endBar(Request $request, string $id, PickupActions $actions): JsonResponse
    {
        $actions->endBar(self::user($request), $id, $request->string('reason')->toString());

        return response()->json(['ok' => true]);
    }

    /**
     * Pick-up for the parent portal: who collected the child today, and who may collect.
     *
     * @response array{child: array{id: string, first_name: string, name: string, class_id: string|null, class: string}, today: array{id: string, state: string, state_label: string, name: string, relationship: string|null, at: string, note: string|null}|null, may_collect: list<array{name: string, relationship: string, day_only: bool}>}
     */
    public function family(Request $request, string $id, Guardianship $guardianship, PickupViews $views): JsonResponse
    {
        $child = $guardianship->child(self::user($request), $id) ?? throw new NotFoundHttpException;

        return response()->json(['child' => $child] + $views->family($id));
    }

    /**
     * A parent names someone to collect the child today. The code is given once.
     *
     * @response 201 array{id: string, code: string}
     */
    public function addForToday(Request $request, string $id, Guardianship $guardianship, PickupActions $actions): JsonResponse
    {
        $parent = self::user($request);
        $guardianship->child($parent, $id) ?? throw new NotFoundHttpException;
        $request->validate(['name' => ['required', 'string', 'max:120'], 'relationship' => ['required', 'string', 'max:60'], 'phone' => ['nullable', 'string', 'max:30']]);
        $phone = $request->string('phone')->toString();

        return response()->json($actions->addForToday($parent, $guardianship, $id, $request->string('name')->toString(), $request->string('relationship')->toString(), $phone !== '' ? $phone : null), 201);
    }

    private static function holdsAny(Access $access, User $who): bool
    {
        $keys = array_map(fn (SafeReleasePermission $p): string => $p->value, SafeReleasePermission::cases());

        foreach ($access->grants($who) as $grant) {
            if (in_array($grant->permission, $keys, true)) {
                return true;
            }
        }

        return false;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
