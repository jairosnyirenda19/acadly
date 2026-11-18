<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Grant;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the People and Person screens show, read for one viewer. A viewer sees
 * a person when one of that person's roles is at a place where the viewer can
 * give roles; a person with no role, only from the whole school. Acting on a
 * person needs the viewer to reach every role the person holds.
 */
final class People
{
    /** An invitation link lasts this many days. */
    public const int INVITATION_DAYS = 7;

    /** Kernel areas, by the first part of a permission key. */
    private const array AREAS = [
        'access' => 'Access', 'identity' => 'Accounts', 'modules' => 'Modules', 'notify' => 'Notices',
        'documents' => 'Documents', 'audit' => 'Audit trail', 'journeys' => 'Processes',
    ];

    public function __construct(
        private readonly Access $access,
        private readonly PermissionCatalogue $catalogue,
        private readonly ModuleRegistry $modules,
        private readonly Clock $clock,
    ) {}

    public function sees(User $viewer, User $person): bool
    {
        $scopes = $this->openScopes($person);

        if ($scopes === []) {
            return $this->access->allows($viewer, AccessPermission::AssignRoles);
        }

        foreach ($scopes as $scope) {
            if ($this->access->allows($viewer, AccessPermission::AssignRoles, $scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{people: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function list(User $viewer, ?ScopeRef $in = null): array
    {
        $inKey = $in !== null ? self::placeKey($in) : null;

        $people = [];
        $counts = ['with_access' => 0, 'invited' => 0, 'expired' => 0, 'no_role' => 0, 'disabled' => 0];

        foreach (User::query()->orderBy('name')->get() as $person) {
            if (! $this->sees($viewer, $person) || ($inKey !== null && ! $this->worksInside($person, $inKey))) {
                continue;
            }

            $roles = $this->rolesOf($person, open: true);
            $state = $this->state($person, $roles !== []);
            $counts[match ($state) {
                'active' => 'with_access', 'invited', 'ready' => 'invited', 'expired' => 'expired', 'no_role' => 'no_role', default => 'disabled',
            }]++;

            $people[] = [
                'id' => $person->id,
                'name' => $person->name,
                'email' => $person->email,
                'state' => $state,
                'last_signed_in_at' => $person->last_signed_in_at?->toIso8601String(),
                'invitation_sent_at' => self::time(DB::table('users')->where('id', $person->id)->value('invitation_sent_at')),
                'disabled' => $state === 'disabled' ? $this->disabled($person) : null,
                'roles' => array_map(fn (array $r): array => ['role' => $r['role'], 'where' => $r['where'], 'ends_at' => $r['ends_at']], $roles),
            ];
        }

        $givers = User::query()->whereIn('id', $this->access->holderIds(AccessPermission::AssignRoles))->orderBy('name')->pluck('name')->all();

        return [
            'people' => $people,
            'totals' => [
                'with_access' => $counts['with_access'],
                'invited' => $counts['invited'] + $counts['expired'],
                'expired' => $counts['expired'],
                'no_role' => $counts['no_role'],
                'disabled' => $counts['disabled'],
                'whole_school_givers' => array_values(array_filter($givers, is_string(...))),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function show(User $viewer, User $person): array
    {
        $open = $this->rolesOf($person, open: true);
        $canAct = $this->access->reaches($viewer, AccessPermission::AssignRoles, $person);

        return [
            'person' => [
                'id' => $person->id,
                'name' => $person->name,
                'email' => $person->email,
                'state' => $this->state($person, $open !== []),
                'last_signed_in_at' => $person->last_signed_in_at?->toIso8601String(),
                'invitation_sent_at' => self::time(DB::table('users')->where('id', $person->id)->value('invitation_sent_at')),
                'disabled' => $person->status === AccountStatus::Disabled ? $this->disabled($person) : null,
                'two_step' => $person->two_step_on_at !== null,
            ],
            'can_act' => $canAct,
            'held' => array_map(fn (array $r): array => [...array_diff_key($r, ['scope' => true]), 'can_end' => $this->access->allows($viewer, AccessPermission::AssignRoles, $r['scope'])], $open),
            'ended' => array_map(fn (array $r): array => array_diff_key($r, ['scope' => true]), $this->rolesOf($person, open: false)),
            'can_do' => $this->canDo($person),
        ];
    }

    /**
     * The roles, places and permissions the viewer can give with: the screen
     * shows why a role is not available at a place; the server decides.
     *
     * @return array{roles: list<array{id: string, name: string, permissions: list<string>}>, places: list<array{type: string, id: string|null, label: string, within: list<string>}>, yours: list<array{permission: string, place: string}>, labels: array<string, string>}
     */
    public function giving(User $viewer): array
    {
        $places = [];

        foreach ($this->access->places() as $place) {
            if (! $this->access->allows($viewer, AccessPermission::AssignRoles, $place['scope'])) {
                continue;
            }

            $places[] = [
                'type' => $place['scope']->type->value,
                'id' => $place['scope']->id,
                'label' => $place['label'],
                'within' => array_map(self::placeKey(...), [$place['scope'], ...$this->access->ancestors($place['scope'])]),
            ];
        }

        $labels = [];

        foreach ($this->catalogue->all() as $key => $permission) {
            $labels[$key] = $permission->label();
        }

        return [
            'roles' => array_values(Role::query()->inForce()->orderByDesc('is_system')->orderBy('name')->get()
                ->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->permissionKeys()])->all()),
            'places' => $places,
            'yours' => array_map(fn (Grant $g): array => ['permission' => $g->permission, 'place' => self::placeKey($g->scope)], $this->access->grants($viewer)),
            'labels' => $labels,
        ];
    }

    /** Whether one of the person's roles is at the place or inside it. */
    private function worksInside(User $person, string $place): bool
    {
        foreach ($this->openScopes($person) as $scope) {
            if (in_array($place, array_map(self::placeKey(...), [$scope, ...$this->access->ancestors($scope)]), true)) {
                return true;
            }
        }

        return false;
    }

    public static function placeKey(ScopeRef $scope): string
    {
        return $scope->type->value.':'.($scope->id ?? '');
    }

    /** @return list<ScopeRef> the places of the roles a person holds now or later */
    private function openScopes(User $person): array
    {
        $scopes = [];

        foreach (RoleAssignment::query()->where('user_id', $person->id)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $this->clock->now()))->get() as $assignment) {
            $scopes[] = $assignment->scope();
        }

        return $scopes;
    }

    private function state(User $person, bool $hasRole): string
    {
        if ($person->status === AccountStatus::Disabled) {
            return 'disabled';
        }

        if ($person->status === AccountStatus::Invited) {
            $sent = DB::table('users')->where('id', $person->id)->value('invitation_sent_at');

            if (! is_string($sent)) {
                return 'ready';
            }

            return $this->clock->now()->subDays(self::INVITATION_DAYS)->greaterThan($sent) ? 'expired' : 'invited';
        }

        return $hasRole ? 'active' : 'no_role';
    }

    /** @return array{at: string|null, reason: string|null} */
    private function disabled(User $person): array
    {
        $event = DB::table('audit_events')->where('action', 'identity.account_disabled')
            ->where('subject_type', $person->auditType())->where('subject_id', $person->id)
            ->orderByDesc('seq')->first(['occurred_at', 'reason']);

        return ['at' => $event !== null ? self::time(Row::maybeText($event, 'occurred_at')) : null, 'reason' => $event !== null ? Row::maybeText($event, 'reason') : null];
    }

    /** How a person's account stands: active, invited, ready, expired, no role or disabled. */
    public function stateOf(User $person): string
    {
        return $this->state($person, $this->openScopes($person) !== []);
    }

    /** @return list<array{role: string, where: string, ends_at: string|null}> the roles a person holds now or later, and where */
    public function rolesNow(User $person): array
    {
        return array_map(fn (array $r): array => ['role' => $r['role'], 'where' => $r['where'], 'ends_at' => $r['ends_at']], $this->rolesOf($person, open: true));
    }

    /**
     * @return list<array{id: string, role: string, where: string, scope: ScopeRef, scope_type: string, scope_id: string|null, starts_at: string, ends_at: string|null, reason: string|null, given_by: string|null, ended_by: string|null, end_reason: string|null}>
     */
    private function rolesOf(User $person, bool $open): array
    {
        $now = $this->clock->now();
        $query = RoleAssignment::query()->with('role')->where('user_id', $person->id);
        $open
            ? $query->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))->orderBy('starts_at')
            : $query->whereNotNull('ends_at')->where('ends_at', '<=', $now)->orderByDesc('ends_at');

        $names = User::query()->pluck('name', 'id')->all();

        return array_values($query->get()->map(fn (RoleAssignment $a): array => [
            'id' => $a->id,
            'role' => $a->role->name,
            'where' => $this->access->label($a->scope()),
            'scope' => $a->scope(),
            'scope_type' => $a->scope_type->value,
            'scope_id' => $a->scope_id,
            'starts_at' => $a->starts_at->toIso8601String(),
            'ends_at' => $a->ends_at?->toIso8601String(),
            'reason' => $a->reason,
            'given_by' => $a->granted_by !== null ? self::name($names[$a->granted_by] ?? null) : null,
            'ended_by' => $a->ended_by !== null ? self::name($names[$a->ended_by] ?? null) : null,
            'end_reason' => $a->end_reason,
        ])->all());
    }

    /**
     * What a person can do, from the permissions of every role they hold and
     * where. Grouped by area, in catalogue order.
     *
     * @return list<array{group: string, permissions: list<array{key: string, label: string, sight: bool, where: list<string>}>}>
     */
    private function canDo(User $person): array
    {
        $where = [];

        foreach ($this->access->grants($person) as $grant) {
            $where[$grant->permission][self::placeKey($grant->scope)] = $this->access->label($grant->scope);
        }

        $groups = [];

        foreach ($this->catalogue->all() as $key => $permission) {
            if (! isset($where[$key])) {
                continue;
            }

            $owner = $this->catalogue->ownerOf($permission);
            $group = self::AREAS[$owner] ?? ($this->modules->has($owner) ? $this->modules->get($owner)->name() : $owner);
            $groups[$group][] = ['key' => $key, 'label' => $permission->label(), 'sight' => $permission->isSight(), 'where' => array_values($where[$key])];
        }

        $out = [];

        foreach ($groups as $group => $permissions) {
            $out[] = ['group' => $group, 'permissions' => $permissions];
        }

        return $out;
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) ? CarbonImmutable::parse($value)->toIso8601String() : null;
    }

    private static function name(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
