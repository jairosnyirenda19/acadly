<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Actions\AssignRole;
use Acadly\Kernel\Access\Actions\EndRoleAssignment;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Access\WorkPlaces;
use Acadly\Kernel\Identity\Actions\DisableAccount;
use Acadly\Kernel\Identity\Actions\InviteAccount;
use Acadly\Kernel\Identity\Actions\SendInvitation;
use Acadly\Kernel\Identity\Actions\SendPasswordReset;
use Acadly\Kernel\Identity\People;
use Acadly\Kernel\Identity\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The People and Person screens (the approved designs). Every request is
 * refused before anything is read or validated when the viewer cannot give
 * roles where it points.
 */
final class PeopleController
{
    public function __construct(
        private readonly Access $access,
        private readonly People $people,
    ) {}

    /**
     * The people the viewer can see, with what they hold and where.
     *
     * @response array{people: list<array{id: string, name: string, email: string, state: 'active'|'ready'|'invited'|'expired'|'no_role'|'disabled', last_signed_in_at: string|null, invitation_sent_at: string|null, disabled: array{at: string|null, reason: string|null}|null, roles: list<array{role: string, where: string, ends_at: string|null}>}>, totals: array{with_access: int, invited: int, expired: int, no_role: int, disabled: int, whole_school_givers: list<string>}, giving: array{roles: list<array{id: string, name: string, permissions: list<string>}>, places: list<array{type: 'institution'|'division'|'campus'|'offering'|'class', id: string|null, label: string, within: list<string>}>, yours: list<array{permission: string, place: string}>, labels: array<string, string>}}
     */
    public function index(Request $request, WorkPlaces $places): JsonResponse
    {
        $viewer = self::user($request);
        $this->refuseWithoutAnyReach($viewer);
        $request->validate(['in' => ['nullable', 'string', 'max:80']]);
        $in = $this->workPlace($request, $viewer, $places);

        return response()->json([...$this->people->list($viewer, $in), 'giving' => $this->people->giving($viewer)]);
    }

    /**
     * The place the viewer works in (`?in=type:id`), only a selector: it is
     * refused unless the viewer holds a role there or around it.
     */
    private function workPlace(Request $request, User $viewer, WorkPlaces $places): ?ScopeRef
    {
        $in = $request->string('in')->toString();

        if ($in === '') {
            return null;
        }

        [$type, $id] = array_pad(explode(':', $in, 2), 2, '');
        $kind = ScopeType::tryFrom($type);

        try {
            $place = $kind !== null ? ScopeRef::of($kind, $id !== '' ? $id : null) : null;
        } catch (InvalidArgumentException) {
            $place = null;
        }

        if ($place === null || ! $places->allows($viewer, $place)) {
            throw new NotFoundHttpException;
        }

        return $place;
    }

    /**
     * One person: roles held and ended, what they can do, and the means to give.
     *
     * @response array{person: array{id: string, name: string, email: string, state: 'active'|'ready'|'invited'|'expired'|'no_role'|'disabled', last_signed_in_at: string|null, invitation_sent_at: string|null, disabled: array{at: string|null, reason: string|null}|null}, can_act: bool, held: list<array{id: string, role: string, where: string, scope_type: string, scope_id: string|null, starts_at: string, ends_at: string|null, reason: string|null, given_by: string|null, ended_by: string|null, end_reason: string|null, can_end: bool}>, ended: list<array{id: string, role: string, where: string, scope_type: string, scope_id: string|null, starts_at: string, ends_at: string|null, reason: string|null, given_by: string|null, ended_by: string|null, end_reason: string|null}>, can_do: list<array{group: string, permissions: list<array{key: string, label: string, sight: bool, where: list<string>}>}>, giving: array{roles: list<array{id: string, name: string, permissions: list<string>}>, places: list<array{type: 'institution'|'division'|'campus'|'offering'|'class', id: string|null, label: string, within: list<string>}>, yours: list<array{permission: string, place: string}>, labels: array<string, string>}}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $viewer = self::user($request);
        $person = $this->visible($viewer, $id);

        return response()->json([...$this->people->show($viewer, $person), 'giving' => $this->people->giving($viewer)]);
    }

    /** Invites a person with a role at a place: `{name, email, role_id, scope_type, scope_id, send}`. */
    public function invite(Request $request, InviteAccount $invite, SendInvitation $send): JsonResponse
    {
        $viewer = self::user($request);
        $at = $this->placeFrom($request);
        $this->access->check($viewer, AccessPermission::AssignRoles, $at);

        $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'string', 'max:255'], 'role_id' => ['required', 'uuid'], 'send' => ['sometimes', 'boolean']]);
        $role = $this->givableRole($request->string('role_id')->toString());

        $person = DB::transaction(fn (): User => $invite->handle($viewer, $request->string('name')->toString(), $request->string('email')->toString(), $role, $at));

        if ($request->boolean('send', true)) {
            $send->handle($viewer, $person);
        }

        return $this->show($request, $person->id);
    }

    /** Sends the invitation again: the earlier link stops working. */
    public function resend(Request $request, string $id, SendInvitation $send): JsonResponse
    {
        $viewer = self::user($request);
        $person = $this->actable($viewer, $id);
        $send->handle($viewer, $person);

        return $this->show($request, $person->id);
    }

    /** Gives a role: `{role_id, scope_type, scope_id, starts_on?, ends_on?, reason}`. */
    public function give(Request $request, string $id, AssignRole $assign): JsonResponse
    {
        $viewer = self::user($request);
        $at = $this->placeFrom($request);
        $this->access->check($viewer, AccessPermission::AssignRoles, $at);
        $person = $this->visible($viewer, $id);

        $request->validate([
            'role_id' => ['required', 'uuid'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
        ], ['reason.required' => 'A reason is required.']);

        $assign->handle($viewer, $person, $this->givableRole($request->string('role_id')->toString()), $at,
            endsAt: self::day($request->input('ends_on'))?->endOfDay(),
            reason: trim($request->string('reason')->toString()),
            startsAt: self::day($request->input('starts_on')));

        return $this->show($request, $person->id);
    }

    /** Ends a role now: `{reason}`. */
    public function end(Request $request, string $id, string $assignment, EndRoleAssignment $end): JsonResponse
    {
        $viewer = self::user($request);
        $person = $this->visible($viewer, $id);
        $held = RoleAssignment::query()->where('user_id', $person->id)->find($assignment) ?? throw (new ModelNotFoundException)->setModel(RoleAssignment::class);
        $this->access->check($viewer, AccessPermission::AssignRoles, $held->scope());

        $end->handle($viewer, $held, $request->string('reason')->toString());

        return $this->show($request, $person->id);
    }

    /** Disables the account: `{reason}`. */
    public function disable(Request $request, string $id, DisableAccount $disable): JsonResponse
    {
        $viewer = self::user($request);
        $person = $this->actable($viewer, $id);
        $disable->handle($viewer, $person, $request->string('reason')->toString());

        return $this->show($request, $person->id);
    }

    /** Sends the person a link to set a new password. */
    public function passwordReset(Request $request, string $id, SendPasswordReset $send): JsonResponse
    {
        $viewer = self::user($request);
        $person = $this->actable($viewer, $id);
        $send->handle($viewer, $person);

        return response()->json(['status' => 'sent']);
    }

    private function refuseWithoutAnyReach(User $viewer): void
    {
        foreach ($this->access->grants($viewer) as $grant) {
            if ($grant->permission === AccessPermission::AssignRoles->key()) {
                return;
            }
        }

        $this->access->check($viewer, AccessPermission::AssignRoles);
    }

    /** A person the viewer can see, or not found. */
    private function visible(User $viewer, string $id): User
    {
        $this->refuseWithoutAnyReach($viewer);
        $person = User::query()->find($id);

        if (! $person instanceof User || ! $this->people->sees($viewer, $person)) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }

        return $person;
    }

    /** A person the viewer reaches wholly: every role they hold is inside the viewer's reach. */
    private function actable(User $viewer, string $id): User
    {
        $person = $this->visible($viewer, $id);

        if (! $this->access->reaches($viewer, AccessPermission::AssignRoles, $person)) {
            $this->access->check($viewer, AccessPermission::AssignRoles);
        }

        return $person;
    }

    /** The place in the request; the whole school when none is given, so a refusal still comes first. */
    private function placeFrom(Request $request): ScopeRef
    {
        $type = ScopeType::tryFrom($request->string('scope_type')->toString());

        if ($type === null) {
            return ScopeRef::institution();
        }

        try {
            return ScopeRef::of($type, $request->string('scope_id')->toString() ?: null);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['scope_id' => ['Choose where the role applies.']]);
        }
    }

    private function givableRole(string $id): Role
    {
        $role = Role::query()->find($id);

        return $role instanceof Role ? $role : throw ValidationException::withMessages(['role_id' => ['Choose a role.']]);
    }

    private static function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $day = null;
        }

        return $day instanceof CarbonImmutable ? $day : null;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
