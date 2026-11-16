<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Timetable;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Deliveries;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Actions\SubmitLessonRegister;
use Acadly\Modules\Attendance\Application\Actions\SubmitRegister;
use Acadly\Modules\Attendance\Contracts\Mark;
use Acadly\Modules\Attendance\Notifications\AbsenceTopic;
use Acadly\Modules\Attendance\Notifications\LessonMissed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Attendance's part of the learner record (the approved learner dashboard):
 * the term at a glance, every morning as a calendar, lessons by subject,
 * absences and missed lessons with who was told and what staff did, and
 * corrections.
 */
final class LearnerAttendance implements LearnerRecordPart
{
    /** @var array<string, array<string, mixed>> */
    private array $known = [];

    public function __construct(
        private readonly AcademicCalendar $calendar,
        private readonly Timetable $timetable,
        private readonly EnrolmentDirectory $learners,
        private readonly SchoolDays $days,
        private readonly Reach $reach,
        private readonly Deliveries $deliveries,
        private readonly Clock $clock,
    ) {}

    public function key(): string
    {
        return 'attendance';
    }

    public function title(): string
    {
        return 'Attendance';
    }

    public function band(User $viewer, string $learnerId): array
    {
        $r = $this->record($viewer, $learnerId);
        /** @var array{marked: int, present: int, absent: int, late: int, missed: int, missed_today: int, share: int|null, last_absent: string|null} $t */
        $t = $r['totals'];

        return [
            ['label' => 'Present this term', 'value' => $t['share'] !== null ? $t['share'].'%' : '–', 'note' => "{$t['present']} of {$t['marked']} school days.", 'tone' => 'plain'],
            ['label' => 'Absent', 'value' => (string) $t['absent'], 'note' => $t['last_absent'] !== null ? 'Days. Last on '.CarbonImmutable::parse($t['last_absent'])->format('l j F').'.' : 'Days this term.', 'tone' => 'plain'],
            ['label' => 'Late', 'value' => (string) $t['late'], 'note' => 'Mornings this term.', 'tone' => 'plain'],
            ['label' => 'Missed a lesson', 'value' => (string) $t['missed'], 'note' => $t['missed_today'] > 0 ? 'Today too.' : 'Here in the morning, absent later.', 'tone' => $t['missed_today'] > 0 ? 'attention' : 'plain'],
        ];
    }

    public function attention(User $viewer, string $learnerId): int
    {
        $totals = $this->record($viewer, $learnerId)['totals'];

        return is_array($totals) && is_int($totals['missed_today'] ?? null) ? $totals['missed_today'] : 0;
    }

    /**
     * Everything Attendance knows about the learner this term.
     *
     * @return array<string, mixed>
     */
    public function record(User $viewer, string $learnerId): array
    {
        if (isset($this->known[$viewer->id.':'.$learnerId])) {
            return $this->known[$viewer->id.':'.$learnerId];
        }

        $place = $this->learners->placeOf($learnerId);

        if ($place === null || ! $this->reach->sees($viewer, $place['class_id'])) {
            throw new NotFoundHttpException;
        }

        $today = $this->clock->today();
        $term = $this->termOf($place['division_id'], $today);
        $from = $term['starts_on'] ?? $today;
        $to = min($term['ends_on'] ?? $today, $today);

        $marks = DB::table('attendance_marks as m')->join('attendance_registers as r', 'r.id', '=', 'm.register_id')
            ->where('m.learner_id', $learnerId)->where('r.day', '>=', $from)->where('r.day', '<=', $to)
            ->orderBy('m.received_at')->orderBy('m.id')
            ->get(['m.id', 'm.register_id', 'm.status', 'm.note', 'm.correction_id', 'r.day', 'r.kind', 'r.subject_id', 'r.activity', 'r.period', 'r.starts', 'r.class_id']);

        // The mark in force is the latest of each register; the first is what the family was told of.
        $now = [];
        $first = [];

        foreach ($marks as $m) {
            $reg = Row::text($m, 'register_id');
            $first[$reg] ??= $m;
            $now[$reg] = $m;
        }

        $morning = [];

        foreach ($now as $m) {
            if (Row::text($m, 'kind') === SubmitRegister::KIND) {
                $morning[substr(Row::text($m, 'day'), 0, 10)] = $m;
            }
        }

        $subjectNames = DB::table('academic_subjects')->whereIn('id', array_values(array_filter(array_map(fn (object $m): ?string => Row::maybeText($m, 'subject_id'), $now))) ?: [Lessons::NONE])->pluck('name', 'id')->all();
        $subjects = [];
        $absences = [];
        $missed = 0;
        $missedToday = 0;

        foreach ($now as $reg => $m) {
            $day = substr(Row::text($m, 'day'), 0, 10);
            $status = Row::text($m, 'status');

            if (Row::text($m, 'kind') === SubmitLessonRegister::KIND) {
                $name = Row::maybeText($m, 'activity') ?? (is_string($subjectNames[Row::maybeText($m, 'subject_id') ?? ''] ?? null) ? $subjectNames[Row::maybeText($m, 'subject_id') ?? ''] : '');
                $subjects[$name] ??= ['subject' => $name, 'present' => 0, 'total' => 0];
                $subjects[$name]['total']++;
                $subjects[$name]['present'] += $status !== Mark::Absent->value ? 1 : 0;
                $wasHere = in_array(isset($morning[$day]) ? Row::text($morning[$day], 'status') : null, [Mark::Present->value, Mark::Late->value], true);

                if ($status === Mark::Absent->value && $wasHere) {
                    $missed++;
                    $missedToday += $day === $today ? 1 : 0;
                    $absences[] = $this->absence($m, $first[$reg], 'missed', $name.' · P'.Row::int($m, 'period'), LessonMissed::SUBJECT);
                }

                continue;
            }

            if ($status === Mark::Absent->value) {
                $absences[] = $this->absence($m, $first[$reg], 'absent', null, AbsenceTopic::SUBJECT);
            }
        }

        $statuses = array_map(fn (object $m): string => Row::text($m, 'status'), $morning);
        $counts = array_count_values($statuses);
        $marked = count($statuses);
        $present = ($counts[Mark::Present->value] ?? 0) + ($counts[Mark::Late->value] ?? 0) + ($counts[Mark::Excused->value] ?? 0);
        $absentDays = array_keys(array_filter($statuses, fn (string $s): bool => $s === Mark::Absent->value));
        rsort($absentDays);

        $out = [
            'term' => $term,
            'today' => $today,
            'totals' => [
                'marked' => $marked,
                'present' => $present,
                'absent' => $counts[Mark::Absent->value] ?? 0,
                'late' => $counts[Mark::Late->value] ?? 0,
                'missed' => $missed,
                'missed_today' => $missedToday,
                'share' => $marked > 0 ? (int) round($present / $marked * 100) : null,
                'last_absent' => $absentDays[0] ?? null,
            ],
            'weeks' => $this->weeks($place['division_id'], $term, $today, $morning),
            'lessons_today' => $this->lessonsToday($place['class_id'], $learnerId, $today),
            'subjects' => array_values($subjects),
            'absences' => $this->withFollowUps($learnerId, $absences),
            'corrections' => $this->corrections($learnerId, $from, $to, $subjectNames),
        ];

        return $this->known[$viewer->id.':'.$learnerId] = $out;
    }

    /** @return array{name: string, starts_on: string, ends_on: string}|null the term that holds the day, or the last one before it */
    private function termOf(string $divisionId, string $day): ?array
    {
        $year = $this->calendar->currentYear($day);
        $chosen = null;

        foreach ($year['terms'][$divisionId] ?? [] as $term) {
            if ($term['starts_on'] <= $day) {
                $chosen = ['name' => $term['name'], 'starts_on' => $term['starts_on'], 'ends_on' => $term['ends_on']];
            }
        }

        return $chosen;
    }

    /**
     * Every week of the term, Monday to Friday, with the morning mark of each school day.
     *
     * @param  array{name: string, starts_on: string, ends_on: string}|null  $term
     * @param  array<string, object>  $morning
     * @return list<array{week: string, days: list<array{day: string, mark: string|null, school_day: bool, future: bool}>}>
     */
    private function weeks(string $divisionId, ?array $term, string $today, array $morning): array
    {
        if ($term === null) {
            return [];
        }

        $out = [];
        $monday = CarbonImmutable::parse($term['starts_on'])->startOfWeek();
        $end = CarbonImmutable::parse($term['ends_on']);

        for ($w = $monday; $w <= $end; $w = $w->addWeek()) {
            $days = [];

            for ($i = 0; $i < 5; $i++) {
                $d = $w->addDays($i)->toDateString();
                $days[] = ['day' => $d, 'mark' => isset($morning[$d]) ? Row::text($morning[$d], 'status') : null,
                    'school_day' => $this->days->isSchoolDay($divisionId, $d), 'future' => $d > $today];
            }

            $out[] = ['week' => $w->toDateString(), 'days' => $days];
        }

        return $out;
    }

    /** @return list<array{period: int, name: string, starts: string, subject: string, kind: string, mark: string|null}> */
    private function lessonsToday(string $classId, string $learnerId, string $today): array
    {
        $lessons = $this->timetable->lessonsOn([$classId], $today);
        $marks = [];

        foreach (DB::table('attendance_marks as m')->join('attendance_registers as r', 'r.id', '=', 'm.register_id')
            ->where('m.learner_id', $learnerId)->where('r.day', $today)->where('r.kind', SubmitLessonRegister::KIND)
            ->orderBy('m.received_at')->get(['r.slot_id', 'm.status']) as $m) {
            $marks[Row::text($m, 'slot_id')] = Row::text($m, 'status');
        }

        return array_map(fn (array $l): array => ['period' => $l['period'], 'name' => $l['name'], 'starts' => $l['starts'], 'subject' => $l['subject'],
            'kind' => $l['kind'], 'mark' => $marks[$l['slot_id']] ?? null], $lessons);
    }

    /** @return array<string, mixed> */
    private function absence(object $now, object $first, string $what, ?string $lesson, string $subject): array
    {
        $told = $this->deliveries->about($subject, [Row::text($first, 'id')])[Row::text($first, 'id')] ?? [];

        return [
            'day' => substr(Row::text($now, 'day'), 0, 10),
            'what' => $what,
            'lesson' => $lesson,
            'register_id' => Row::text($now, 'register_id'),
            'reason' => Row::maybeText($now, 'note'),
            'told' => array_map(fn (array $d): array => ['to' => $d['to'], 'channel' => $d['channel'], 'state' => $d['state'], 'at' => $d['at']], $told),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $absences
     * @return list<array<string, mixed>>
     */
    private function withFollowUps(string $learnerId, array $absences): array
    {
        $registers = array_values(array_filter(array_map(fn (array $a): mixed => $a['register_id'] ?? null, $absences), is_string(...)));
        $steps = DB::table('attendance_follow_ups')->where('learner_id', $learnerId)->whereIn('register_id', $registers ?: [Lessons::NONE])->orderBy('done_at')->get();
        $names = DB::table('users')->whereIn('id', array_map(fn (object $s): string => Row::text($s, 'done_by'), $steps->all()) ?: [Lessons::NONE])->pluck('name', 'id')->all();

        $out = array_map(fn (array $a): array => [...$a, 'follow_ups' => array_values(array_map(fn (object $s): array => [
            'kind' => Row::text($s, 'kind'), 'by' => is_string($names[Row::text($s, 'done_by')] ?? null) ? $names[Row::text($s, 'done_by')] : '',
            'at' => Row::text($s, 'done_at'), 'note' => Row::maybeText($s, 'note'),
        ], array_filter($steps->all(), fn (object $s): bool => Row::text($s, 'register_id') === $a['register_id'])))], $absences);

        usort($out, fn (array $x, array $y): int => strcmp(is_string($y['day']) ? $y['day'] : '', is_string($x['day']) ? $x['day'] : ''));

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $subjectNames
     * @return list<array{day: string, register: string, from: string, to: string, reason: string, asked_by: string, state: string, at: string}>
     */
    private function corrections(string $learnerId, string $from, string $to, array $subjectNames): array
    {
        $rows = DB::table('attendance_corrections as c')->join('attendance_registers as r', 'r.id', '=', 'c.register_id')
            ->where('c.learner_id', $learnerId)->where('r.day', '>=', $from)->where('r.day', '<=', $to)->orderByDesc('c.created_at')
            ->get(['r.day', 'r.kind', 'r.subject_id', 'r.activity', 'r.period', 'c.from_status', 'c.to_status', 'c.reason', 'c.requested_by', 'c.state', 'c.created_at']);
        $names = DB::table('users')->whereIn('id', array_map(fn (object $c): string => Row::text($c, 'requested_by'), $rows->all()) ?: [Lessons::NONE])->pluck('name', 'id')->all();

        return array_values(array_map(fn (object $c): array => [
            'day' => substr(Row::text($c, 'day'), 0, 10),
            'register' => Row::text($c, 'kind') === SubmitRegister::KIND ? 'morning'
                : (Row::maybeText($c, 'activity') ?? (is_string($subjectNames[Row::maybeText($c, 'subject_id') ?? ''] ?? null) ? $subjectNames[Row::maybeText($c, 'subject_id') ?? ''] : '')).' · P'.Row::int($c, 'period'),
            'from' => Row::text($c, 'from_status'),
            'to' => Row::text($c, 'to_status'),
            'reason' => Row::text($c, 'reason'),
            'asked_by' => is_string($names[Row::text($c, 'requested_by')] ?? null) ? $names[Row::text($c, 'requested_by')] : '',
            'state' => Row::text($c, 'state'),
            'at' => Row::text($c, 'created_at'),
        ], $rows->all()));
    }
}
