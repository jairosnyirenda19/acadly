<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

use Acadly\Kernel\Identity\User;

/**
 * A module's figures on the Divisions start screen (D-096): for each
 * division, what the viewer may see of it today. Tagged with TAG; a module
 * that is off adds nothing. No money and no learner names: the start screen
 * is often seen by others in the room.
 *
 * @phpstan-type Figures array{learners?: int, present?: int, marked?: int, state?: array{tone: 'done'|'attention'|'wrong', text: string}}
 */
interface DivisionFigures
{
    public const string TAG = 'institution.division_figures';

    /** The module's key: its figures show only while it is on. */
    public function key(): string;

    /**
     * @param  list<string>  $divisionIds
     * @return array<string, Figures> division id => figures
     */
    public function figures(User $viewer, array $divisionIds, string $day): array;
}
