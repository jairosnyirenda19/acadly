<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Search;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Application\Learners;
use Acadly\Kernel\Access\Boundary;
use Acadly\Kernel\Identity\User;

/**
 * The classes this year whose learners the reader's learner list shows
 * (12-search rule 4, 11): every learner source starts from them.
 */
final class VisibleClasses
{
    /** @var array<string, list<array{id: string, name: string, division_id: string, level: string, campus: string, can_admit: bool}>> */
    private array $known = [];

    public function __construct(
        private readonly Learners $learners,
        private readonly AcademicCalendar $calendar,
    ) {}

    /** @return list<array{id: string, name: string, division_id: string, level: string, campus: string, can_admit: bool}> */
    public function of(User $who): array
    {
        $year = $this->calendar->currentYear();

        return $this->known[Boundary::cacheKey($who)] ??= $year === null ? [] : $this->learners->visibleClasses($who, $year['id'], null);
    }
}
