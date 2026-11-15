<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Contracts;

use Acadly\Kernel\Identity\User;

/**
 * The school's structure, for other core domains and modules (01 §4): its
 * divisions with their profiles, its campuses, and which division is taught
 * at which campus. Read-only, except for opening the school, which follows
 * the checks of the caller.
 */
interface SchoolDirectory
{
    /** @return list<DivisionView> the divisions in force, in order */
    public function divisionViews(): array;

    public function division(string $id): ?DivisionView;

    /** @return list<CampusView> the campuses in force, in order */
    public function campusViews(): array;

    public function campus(string $id): ?CampusView;

    /** @return list<array{division_id: string, campus_id: string}> where each division is taught */
    public function offerings(): array;

    public function offers(string $divisionId, string $campusId): bool;

    /** The details are complete: name, short name, code, contacts, district and country. */
    public function detailsComplete(): bool;

    public function isOpen(): bool;

    /** Opens the school: its code is fixed and its work becomes available. Recorded in the audit trail. */
    public function open(User $by): void;
}
