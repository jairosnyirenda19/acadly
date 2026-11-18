<?php

declare(strict_types=1);

namespace Acadly\Kernel\Search;

use Acadly\Kernel\Identity\User;

/**
 * One place search looks (12-search §1, D-089). A module registers its
 * sources with TAG. The rules every source keeps (12-search §2):
 *
 * - it starts from its own list screen's scoped query, and matches only
 *   fields that list shows this reader;
 * - `available()` asks the same question as that list: no permission, and
 *   the group is absent, never empty;
 * - it returns SearchHit values, never rows or counts of what is hidden.
 */
interface SearchSource
{
    public const string TAG = 'kernel.search_sources';

    /** Unique: 'learners'. */
    public function key(): string;

    /** The module that owns it, or null for a core that is always on. */
    public function module(): ?string;

    public function label(): string;

    /** The group's place in the fixed order: lower first. */
    public function position(): int;

    public function available(User $who): bool;

    /** @return list<SearchHit> at most `$query->limit + 1`: the extra one only says there are more */
    public function search(User $who, SearchQuery $query): array;
}
