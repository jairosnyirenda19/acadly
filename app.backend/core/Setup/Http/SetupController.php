<?php

declare(strict_types=1);

namespace Acadly\Core\Setup\Http;

use Acadly\Core\Institution\Contracts\InstitutionPermission;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Core\Setup\Application\Actions\InviteStaff;
use Acadly\Core\Setup\Application\Actions\OpenSchool;
use Acadly\Core\Setup\Application\SetupProgress;
use Acadly\Core\Setup\Application\StarterRoles;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\Actions\SendInvitation;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SetupController
{
    public function __construct(
        private readonly SetupProgress $progress,
        private readonly SchoolDirectory $school,
    ) {}

    /**
     * Where setup stands: each step, the staff list, the roles and places a
     * person can be invited to, and what still blocks opening.
     *
     * @response array{open: bool, steps: list<array{key: string, done: bool}>, blockers: list<string>, administrators: int, waiting: int, staff: list<array{id: string, name: string, email: string, role: string, works_in: string, state: 'signed_in'|'active'|'ready'|'sent'}>, roles: list<array{key: string, name: string, scope: string}>, places: list<array{type: 'institution'|'division'|'campus'|'offering', id: string|null, name: string}>}
     */
    public function show(Request $request, Access $access): JsonResponse
    {
        $access->check(self::user($request), InstitutionPermission::Manage);

        $roles = [];

        foreach (StarterRoles::ALL as $key => $starter) {
            $roles[$key] = ['key' => $key, 'name' => $starter['name'], 'scope' => $starter['scope']];
        }

        foreach (Role::query()->where('is_system', false)->orderBy('name')->get() as $role) {
            $roles[$role->key] ??= ['key' => $role->key, 'name' => $role->name, 'scope' => ''];
        }

        $places = [['type' => 'institution', 'id' => null, 'name' => 'Whole school']];

        foreach ($this->school->divisionViews() as $division) {
            $places[] = ['type' => 'division', 'id' => $division->id, 'name' => $division->name];
        }

        $divisionNames = [];
        $campusNames = [];

        foreach ($this->school->divisionViews() as $division) {
            $divisionNames[$division->id] = $division->name;
        }

        foreach ($this->school->campusViews() as $campus) {
            $places[] = ['type' => 'campus', 'id' => $campus->id, 'name' => $campus->name];
            $campusNames[$campus->id] = $campus->name;
        }

        // A level at one campus, where a level is taught at more than one campus.
        $offerings = $this->school->offerings();
        $campusesOf = array_count_values(array_column($offerings, 'division_id'));

        foreach ($offerings as $offering) {
            if (($campusesOf[$offering['division_id']] ?? 0) > 1) {
                $places[] = ['type' => 'offering', 'id' => $offering['id'],
                    'name' => ($divisionNames[$offering['division_id']] ?? '').' · '.($campusNames[$offering['campus_id']] ?? '')];
            }
        }

        $steps = [];

        foreach ($this->progress->steps() as $key => $done) {
            $steps[] = ['key' => $key, 'done' => $done];
        }

        return response()->json([
            'open' => $this->school->isOpen(),
            'steps' => $steps,
            'blockers' => $this->progress->blockers(),
            'administrators' => $this->progress->administrators(),
            'waiting' => $this->progress->invitationsWaiting(),
            'staff' => $this->staff($places),
            'roles' => array_values($roles),
            'places' => $places,
        ]);
    }

    /** Adds a member of staff to the list: `{name, email, role, place_type, place_id}`. The invitation is not sent yet. */
    public function invite(Request $request, InviteStaff $invite, Access $access): JsonResponse
    {
        $access->check(self::user($request), InstitutionPermission::Manage);
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:60'],
            'place_type' => ['required', 'in:institution,division,campus,offering'],
            'place_id' => ['nullable', 'uuid'],
        ]);

        try {
            $at = ScopeRef::of(ScopeType::from($request->string('place_type')->toString()), $request->string('place_id')->toString() ?: null);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['place_id' => ['Choose where the person works.']]);
        }

        $invite->handle(self::user($request), $request->string('name')->toString(), $request->string('email')->toString(),
            $request->string('role')->toString(), $at);

        return $this->show($request, $access);
    }

    /** Sends every invitation that is ready. */
    public function send(Request $request, SendInvitation $send, Access $access): JsonResponse
    {
        $by = self::user($request);

        foreach (User::query()->where('status', AccountStatus::Invited->value)->whereNull('invitation_sent_at')->get() as $user) {
            $send->handle($by, $user);
        }

        return $this->show($request, $access);
    }

    /** Opens the school. Refused with the reasons while a required step is not done. */
    public function open(Request $request, OpenSchool $open, Access $access): JsonResponse
    {
        $open->handle(self::user($request));

        return $this->show($request, $access);
    }

    /**
     * @param  list<array{type: string, id: string|null, name: string}>  $places
     * @return list<array{id: string, name: string, email: string, role: string, works_in: string, state: string}>
     */
    private function staff(array $places): array
    {
        $names = [];

        foreach ($places as $place) {
            $names[$place['type'].':'.($place['id'] ?? '')] = $place['name'];
        }

        $rows = DB::table('users as u')
            ->join('access_role_assignments as a', 'a.user_id', '=', 'u.id')
            ->join('access_roles as r', 'r.id', '=', 'a.role_id')
            ->whereIn('u.status', [AccountStatus::Active->value, AccountStatus::Invited->value])
            ->where(fn (Builder $q): Builder => $q->whereNull('a.ends_at')->orWhere('a.ends_at', '>', now()))
            ->orderBy('u.created_at')
            ->get(['u.id', 'u.name', 'u.email', 'u.status', 'u.last_signed_in_at', 'u.invitation_sent_at', 'r.name as role', 'a.scope_type', 'a.scope_id']);

        $out = [];

        foreach ($rows as $row) {
            $status = Row::text($row, 'status');
            $state = match (true) {
                $status === AccountStatus::Active->value && Row::maybeText($row, 'last_signed_in_at') !== null => 'signed_in',
                $status === AccountStatus::Active->value => 'active',
                Row::maybeText($row, 'invitation_sent_at') !== null => 'sent',
                default => 'ready',
            };

            $out[] = [
                'id' => Row::text($row, 'id'),
                'name' => Row::text($row, 'name'),
                'email' => Row::text($row, 'email'),
                'role' => Row::text($row, 'role'),
                'works_in' => $names[Row::text($row, 'scope_type').':'.(Row::maybeText($row, 'scope_id') ?? '')] ?? '',
                'state' => $state,
            ];
        }

        return $out;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
