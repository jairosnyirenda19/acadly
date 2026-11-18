<?php

declare(strict_types=1);

namespace Acadly\Kernel\Search;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Identity\People;
use Acadly\Kernel\Identity\User;

/**
 * Staff (12-search §6): whoever may open People finds the people that list
 * shows them, by name or email. Accounts that sign in by phone only are
 * families, not staff.
 */
final class StaffSearch implements SearchSource
{
    public function __construct(
        private readonly Access $access,
        private readonly People $people,
    ) {}

    public function key(): string
    {
        return 'staff';
    }

    public function module(): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Staff';
    }

    public function position(): int
    {
        return 50;
    }

    public function available(User $who): bool
    {
        foreach ($this->access->grants($who) as $grant) {
            if ($grant->permission === AccessPermission::AssignRoles->key()) {
                return true;
            }
        }

        return false;
    }

    public function search(User $who, SearchQuery $query): array
    {
        $base = $query->words(User::query()->toBase(), "coalesce(name, '') || ' ' || coalesce(email, '')")->whereNotNull('email');
        $found = $query->startsFirst($base, 'name')->orderBy('name')
            ->limit(($query->limit + 1 + $query->offset) * 3)->get(['id']);
        $hits = [];

        foreach ($found as $row) {
            $person = User::query()->find($row->id);

            // The People list's own rule decides who this reader sees.
            if ($person instanceof User && $this->people->sees($who, $person)) {
                $hits[] = new SearchHit($person->id, 'person', $person->name, null, $person->email);
            }
        }

        return array_slice($hits, $query->offset, $query->limit + 1);
    }
}
