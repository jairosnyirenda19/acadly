<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Grid\BulkResult;
use Acadly\Kernel\Grid\GridColumn;
use Acadly\Kernel\Grid\GridDefinition;
use Acadly\Kernel\Grid\GridFilter;
use Acadly\Kernel\Grid\GridOption;
use Acadly\Kernel\Identity\Actions\SendInvitation;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The People list on the data grid (the approved design): everyone the
 * viewer may give roles to or see, with what they hold and how their
 * account stands. Staff lists are small, so who may be seen and each
 * person's state are worked out once, then filtered and paged in SQL.
 */
final class PeopleGrid implements GridDefinition
{
    private const array STATES = ['active' => 'Active', 'invited' => 'Invited', 'ready' => 'Invitation accepted', 'expired' => 'Invitation expired',
        'no_role' => 'No role', 'disabled' => 'Disabled'];

    /** @var array<string, array<string, string>> viewer id => person id => state */
    private array $states = [];

    public function __construct(
        private readonly Access $access,
        private readonly People $people,
        private readonly SendInvitation $invitations,
        private readonly Clock $clock,
    ) {}

    public function key(): string
    {
        return 'people';
    }

    public function title(): string
    {
        return 'People';
    }

    public function query(User $viewer): Builder
    {
        $states = $this->statesFor($viewer);

        return DB::table('users as u')->whereIn('u.id', array_keys($states) ?: ['00000000-0000-0000-0000-000000000000'])
            ->select(['u.id', 'u.name', 'u.email', 'u.last_signed_in_at', 'u.invitation_sent_at']);
    }

    public function idColumn(): string
    {
        return 'u.id';
    }

    public function columns(): array
    {
        return [
            new GridColumn('name', 'Name', 'lower(u.name)', always: true),
            new GridColumn('email', 'Email', 'lower(u.email)', shown: false),
            new GridColumn('roles', 'Roles and places'),
            new GridColumn('state', 'State'),
            new GridColumn('last_signed_in_at', 'Last signed in', 'u.last_signed_in_at'),
            new GridColumn('invitation_sent_at', 'Invitation sent', 'u.invitation_sent_at', shown: false),
        ];
    }

    public function filters(User $viewer): array
    {
        $states = $this->statesFor($viewer);
        $now = $this->clock->now();
        $options = [];

        foreach (self::STATES as $state => $label) {
            $ids = array_keys(array_filter($states, fn (string $s): bool => $s === $state));
            $options[] = new GridOption($state, $label, fn (Builder $q): Builder => $q->whereIn('u.id', $ids ?: ['00000000-0000-0000-0000-000000000000']));
        }

        $roles = array_values(Role::query()->inForce()->orderBy('name')->get()->map(fn (Role $role): GridOption => new GridOption($role->id, $role->name,
            fn (Builder $q): Builder => $q->whereIn('u.id', DB::table('access_role_assignments')->select('user_id')->where('role_id', $role->id)
                ->where('starts_at', '<=', $now)->where(fn (Builder $w): Builder => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now)))))->all());

        return [GridFilter::choice('state', 'State', $options), GridFilter::choice('role', 'Role', $roles), GridFilter::dates('signed_in', 'Last signed in', 'u.last_signed_in_at')];
    }

    public function search(Builder $query, string $text): void
    {
        $q = '%'.mb_strtolower($text).'%';
        $query->where(fn (Builder $x): Builder => $x->whereRaw('lower(u.name) like ?', [$q])->orWhereRaw('lower(u.email) like ?', [$q]));
    }

    public function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function rows(User $viewer, array $rows): array
    {
        $states = $this->statesFor($viewer);
        $users = User::query()->whereIn('id', array_map(fn (object $r): string => Row::text($r, 'id'), $rows))->get()->keyBy('id');

        return array_map(function (object $r) use ($states, $users): array {
            $person = $users->get(Row::text($r, 'id'));

            return [
                'id' => Row::text($r, 'id'),
                'name' => Row::text($r, 'name'),
                'email' => Row::text($r, 'email'),
                'state' => $states[Row::text($r, 'id')] ?? 'no_role',
                'roles' => $person instanceof User ? $this->people->rolesNow($person) : [],
                'last_signed_in_at' => Row::maybeText($r, 'last_signed_in_at'),
                'invitation_sent_at' => Row::maybeText($r, 'invitation_sent_at'),
            ];
        }, $rows);
    }

    public function plain(array $row, string $column): string|int|null
    {
        $value = match ($column) {
            'state' => self::STATES[is_string($row['state'] ?? null) ? $row['state'] : ''] ?? null,
            'roles' => implode('; ', array_map(fn (mixed $r): string => is_array($r) && is_string($r['role'] ?? null) && is_string($r['where'] ?? null) ? $r['role'].', '.$r['where'] : '',
                is_array($row['roles'] ?? null) ? $row['roles'] : [])),
            default => $row[$column] ?? null,
        };

        return is_string($value) || is_int($value) ? $value : null;
    }

    public function canExport(User $viewer): bool
    {
        return $this->statesFor($viewer) !== [];
    }

    public function bulkActions(User $viewer): array
    {
        return [['key' => 'resend', 'label' => 'Resend invitations']];
    }

    public function bulk(User $viewer, string $action, array $ids, array $input): BulkResult
    {
        $result = new BulkResult;

        foreach (User::query()->whereIn('id', $ids)->orderBy('name')->get() as $person) {
            try {
                if (! $this->access->reaches($viewer, AccessPermission::AssignRoles, $person)) {
                    throw new AuthorizationException;
                }

                $this->invitations->handle($viewer, $person);
                $result->did();
            } catch (ValidationException $e) {
                $result->refuse($person->id, $person->name, $e->validator->errors()->first());
            } catch (AuthorizationException) {
                $result->refuse($person->id, $person->name, 'You cannot act for this person.');
            }
        }

        return $result;
    }

    /** @return array<string, string> person id => state, for everyone the viewer may see */
    private function statesFor(User $viewer): array
    {
        if (isset($this->states[$viewer->id])) {
            return $this->states[$viewer->id];
        }

        $reach = false;

        foreach ($this->access->grants($viewer) as $grant) {
            $reach = $reach || $grant->permission === AccessPermission::AssignRoles->key();
        }

        if (! $reach) {
            $this->access->check($viewer, AccessPermission::AssignRoles);
        }

        $out = [];

        foreach (User::query()->get() as $person) {
            if ($this->people->sees($viewer, $person)) {
                $out[$person->id] = $this->people->stateOf($person);
            }
        }

        return $this->states[$viewer->id] = $out;
    }
}
