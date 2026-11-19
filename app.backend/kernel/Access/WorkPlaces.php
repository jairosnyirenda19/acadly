<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;

/**
 * The places an account can work in (the approved "Work in" designs): every
 * place where it holds a role now, and every place inside one of them. Each
 * place carries the keys of the places that contain it, so a screen can tell
 * what is inside what without asking again.
 *
 * Choosing a place only narrows what a page shows. What may be done there
 * still comes from the roles held, checked on every request.
 */
final class WorkPlaces
{
    public function __construct(
        private readonly Access $access,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<array{key: string, type: string, id: string|null, label: string, within: list<string>, held: bool, roles: list<array{role: string, ends_at: string|null}>}>
     */
    public function of(User $user): array
    {
        $roles = [];
        $heldScopes = [];

        foreach (RoleAssignment::query()->with('role')->activeAt($this->clock->now())->where('user_id', $user->id)->orderBy('starts_at')->get() as $assignment) {
            $scope = $assignment->scope();
            $key = self::key($scope);
            $heldScopes[$key] = $scope;
            $roles[$key][] = ['role' => $assignment->role->name, 'ends_at' => $assignment->ends_at?->toIso8601String()];
        }

        if ($heldScopes === []) {
            return [];
        }

        $out = [];
        $listed = [];

        foreach ($this->access->places() as $place) {
            $within = $this->within($place['scope']);

            if (array_intersect($within, array_keys($heldScopes)) === []) {
                continue;
            }

            $key = self::key($place['scope']);
            $listed[$key] = true;
            $out[] = self::row($place['scope'], $place['label'], $within, $roles[$key] ?? []);
        }

        // A module's places (a finance office): listed for the switcher; not a role held.
        foreach (app()->tagged(PlaceGroups::TAG) as $groups) {
            if ($groups instanceof PlaceGroups) {
                foreach ($groups->groupsFor($user) as $g) {
                    $out[] = ['key' => $groups->prefix().':'.$g['id'], 'type' => $groups->prefix(), 'id' => $g['id'], 'label' => $g['label'], 'within' => [$groups->prefix().':'.$g['id'], 'institution:'], 'held' => false, 'roles' => []];
                }
            }
        }

        // A place held that is not listed (a level taught at one campus only) still belongs.
        foreach ($heldScopes as $key => $scope) {
            if (! isset($listed[$key])) {
                $out[] = self::row($scope, $this->access->label($scope), $this->within($scope), $roles[$key]);
            }
        }

        return $out;
    }

    /** Whether the account may work in the place: it holds a role there or around it. */
    public function allows(User $user, ScopeRef $place): bool
    {
        foreach ($this->of($user) as $row) {
            if ($row['key'] === self::key($place)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the place and every place that contains it */
    public function within(ScopeRef $scope): array
    {
        return array_map(self::key(...), [$scope, ...$this->access->ancestors($scope)]);
    }

    public static function key(ScopeRef $scope): string
    {
        return $scope->type->value.':'.($scope->id ?? '');
    }

    /**
     * @param  list<string>  $within
     * @param  list<array{role: string, ends_at: string|null}>  $roles
     * @return array{key: string, type: string, id: string|null, label: string, within: list<string>, held: bool, roles: list<array{role: string, ends_at: string|null}>}
     */
    private static function row(ScopeRef $scope, string $label, array $within, array $roles): array
    {
        return ['key' => self::key($scope), 'type' => $scope->type->value, 'id' => $scope->id, 'label' => $label, 'within' => $within, 'held' => $roles !== [], 'roles' => $roles];
    }
}
