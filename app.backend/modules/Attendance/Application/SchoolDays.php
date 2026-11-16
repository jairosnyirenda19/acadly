<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use DateTimeImmutable;

/**
 * School days (first release): the days of a term of the class's level,
 * Monday to Friday. Holidays inside a term follow with the school calendar.
 */
final class SchoolDays
{
    public function __construct(private readonly AcademicCalendar $calendar) {}

    public function isSchoolDay(string $divisionId, string $day): bool
    {
        if ((int) (new DateTimeImmutable($day))->format('N') > 5) {
            return false;
        }

        $year = $this->calendar->currentYear($day);

        if ($year === null || $day < $year['starts_on'] || $day > $year['ends_on']) {
            return false;
        }

        foreach ($year['terms'][$divisionId] ?? [] as $term) {
            if ($day >= $term['starts_on'] && $day <= $term['ends_on']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The school days before a day, nearest first.
     *
     * @return list<string>
     */
    public function before(string $divisionId, string $day, int $count): array
    {
        $out = [];
        $at = new DateTimeImmutable($day);

        for ($i = 0; $i < 60 && count($out) < $count; $i++) {
            $at = $at->modify('-1 day');
            $candidate = $at->format('Y-m-d');

            if ($this->isSchoolDay($divisionId, $candidate)) {
                $out[] = $candidate;
            }
        }

        return $out;
    }
}
