<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

use Acadly\Kernel\Identity\User;

/**
 * A kind of place a module adds to the places one can work in (D-099): a
 * named group of offerings, such as a finance office that serves two
 * divisions. Inside such a place only the module's own permissions (and those
 * it names) are open, and only for the offerings of the group. Tagged
 * `PlaceGroups::TAG`; the kernel names no module.
 *
 * @phpstan-type Group array{id: string, label: string, offerings: list<string>, modules: list<string>}
 */
interface PlaceGroups
{
    public const string TAG = 'acadly.place-groups';

    /** The key prefix of its places: 'office' for 'office:<id>'. */
    public function prefix(): string;

    /**
     * The groups the account may work in.
     *
     * @return list<Group>
     */
    public function groupsFor(User $user): array;

    /**
     * One group, or null when there is none with this id.
     *
     * @return Group|null
     */
    public function group(string $id): ?array;
}
