<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Contracts;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;

/**
 * What a dashboard is drawn for (D-100): the person, the divisions it covers
 * and the day. In the whole-school overview it covers every division, and a
 * division counts only where the person holds the permission there by their
 * roles (figures, never a record). Inside a division it covers that division,
 * checked as every other request is.
 */
final readonly class DashboardScope
{
    /**
     * @param  list<array{id: string, name: string, profile: string}>  $divisions  in the school's order
     */
    public function __construct(
        public User $viewer,
        public array $divisions,
        public bool $school,
        public string $day,
        private Access $access,
    ) {}

    /**
     * The divisions of the scope where the person holds the permission.
     *
     * @return list<string>
     */
    public function sees(Permission $permission): array
    {
        $out = [];

        foreach ($this->divisions as $d) {
            $at = ScopeRef::of(ScopeType::Division, $d['id']);

            if ($this->school ? $this->access->holdsForCount($this->viewer, $permission, $at) : $this->access->allows($this->viewer, $permission, $at)) {
                $out[] = $d['id'];
            }
        }

        return $out;
    }

    /** The name of a division of the scope. */
    public function name(string $divisionId): string
    {
        foreach ($this->divisions as $d) {
            if ($d['id'] === $divisionId) {
                return $d['name'];
            }
        }

        return '';
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_column($this->divisions, 'id');
    }
}
