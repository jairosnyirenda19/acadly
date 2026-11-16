<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Contracts;

/**
 * What other domains may know of the timetable. A lesson is one timetable row
 * on one day: `slot_id` and `day` together name it, and stay the same after
 * the timetable changes. Times are the school's own, `HH:MM`.
 *
 * @phpstan-type Bell array{number: int|null, name: string, starts: string, ends: string, kind: 'lesson'|'break'}
 * @phpstan-type Lesson array{slot_id: string, class_id: string, day: string, period: int, name: string, starts: string, ends: string, subject_id: string, subject: string, subject_short: string, teacher_id: string|null}
 */
interface Timetable
{
    /**
     * The periods and breaks of a level at a campus on a day, in order. A
     * lesson period has its number (1, 2, …); a break has none. Empty when
     * no bell times cover that day.
     *
     * @return list<Bell>
     */
    public function bells(string $divisionId, string $campusId, string $day): array;

    /**
     * The lessons of these classes on a day, in the order of their periods.
     *
     * @param  list<string>  $classIds
     * @return list<Lesson>
     */
    public function lessonsOn(array $classIds, string $day): array;

    /**
     * The lessons a teacher is timetabled to teach on a day.
     *
     * @return list<Lesson>
     */
    public function lessonsOf(string $userId, string $day): array;

    /**
     * One lesson, or null when the row does not apply on that day.
     *
     * @return Lesson|null
     */
    public function lesson(string $slotId, string $day): ?array;
}
