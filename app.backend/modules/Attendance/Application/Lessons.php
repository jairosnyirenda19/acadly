<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Timetable;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Deliveries;
use Acadly\Kernel\Notify\MessageSettings;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Application\Actions\SubmitLessonRegister;
use Acadly\Modules\Attendance\Application\Actions\SubmitRegister;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Acadly\Modules\Attendance\Contracts\Mark;
use Acadly\Modules\Attendance\Notifications\LessonMissed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the lesson screens show (the approved Timetable and lesson register
 * designs): a teacher's day, every lesson of the day for the office, one
 * lesson's register, missed lessons, and who is free to cover.
 *
 * A lesson's state: `taken` or `cover` once its register is in; otherwise
 * `no_register` once the period has ended, `needs_cover` while its teacher
 * is away and nobody is asked, `now` during the period, and `later` before.
 *
 * @phpstan-import-type Lesson from Timetable
 */
final class Lessons
{
    public const int OPENS_BEFORE = 15;

    /** An id no row has, for a list that would otherwise be empty. */
    public const string NONE = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly Timetable $timetable,
        private readonly EnrolmentDirectory $learners,
        private readonly SchoolDirectory $school,
        private readonly SchoolDays $days,
        private readonly Modes $modes,
        private readonly Marks $marks,
        private readonly Reach $reach,
        private readonly Deliveries $deliveries,
        private readonly MessageSettings $messages,
        private readonly Clock $clock,
    ) {}

    /** @param Lesson $lesson */
    public static function opened(array $lesson, Clock $clock): bool
    {
        $today = $clock->today();

        if ($lesson['day'] !== $today) {
            return $lesson['day'] < $today;
        }

        return self::clock($clock) >= CarbonImmutable::parse('2000-01-01 '.$lesson['starts'])->subMinutes(self::OPENS_BEFORE)->format('H:i');
    }

    /** The time now where the school is: 10:20. */
    public static function clock(Clock $clock): string
    {
        return $clock->now()->setTimezone(config()->string('acadly.timezone', 'UTC'))->format('H:i');
    }

    /**
     * How each level takes registers.
     *
     * @return list<array{division: string, mode: string}>
     */
    public function levels(User $viewer): array
    {
        $this->visible($viewer, $this->clock->today());

        return array_map(fn ($d): array => ['division' => $d->name, 'mode' => $this->modes->of($d->id)->value], $this->school->divisionViews());
    }

    /**
     * A teacher's day: the morning register of their class, and their lessons, with cover they took or were asked to take.
     *
     * @return array<string, mixed>
     */
    public function myDay(User $viewer, string $day): array
    {
        $year = $this->calendar->currentYear($day);
        $morning = [];

        foreach ($year === null ? [] : $this->calendar->classesInYear($year['id']) as $class) {
            if ($this->calendar->classTeacherId($class['id']) === $viewer->id) {
                $register = DB::table('attendance_registers')->where('class_id', $class['id'])->where('day', $day)->where('kind', SubmitRegister::KIND)->first(['id', 'submitted_at']);
                $morning[] = ['class_id' => $class['id'], 'class' => $class['name'], 'school_day' => $this->days->isSchoolDay($class['division_id'], $day),
                    'submitted_at' => $register !== null ? Row::text($register, 'submitted_at') : null];
            }
        }

        $asked = DB::table('attendance_cover_asks')->where('day', $day)->orderBy('asked_at')->get(['slot_id', 'user_id', 'asked_by']);
        $askedOfMe = [];

        foreach ($asked as $a) {
            $askedOfMe[Row::text($a, 'slot_id')] = Row::text($a, 'user_id') === $viewer->id ? Row::text($a, 'asked_by') : null;
        }

        $covered = DB::table('attendance_registers')->where('day', $day)->where('kind', SubmitLessonRegister::KIND)->where('submitted_by', $viewer->id)->where('cover', true)->pluck('slot_id')->all();
        $lessons = $this->timetable->lessonsOf($viewer->id, $day);
        $mine = array_column($lessons, 'slot_id');

        foreach ([...array_keys(array_filter($askedOfMe)), ...array_filter($covered, is_string(...))] as $slot) {
            $lesson = in_array($slot, $mine, true) ? null : $this->timetable->lesson((string) $slot, $day);

            if ($lesson !== null && ! in_array($lesson['slot_id'], array_column($lessons, 'slot_id'), true)) {
                $lessons[] = $lesson;
            }
        }

        usort($lessons, fn (array $a, array $b): int => $a['starts'] <=> $b['starts']);

        if ($lessons === [] && $morning === []) {
            $this->access->check($viewer, AttendancePermission::Capture);
        }

        $rows = $this->states($lessons, $day);
        $names = $this->names([...array_values(array_filter($askedOfMe)), ...array_filter(array_column($lessons, 'teacher_id'))]);
        $away = $this->awayOn($day)[$viewer->id] ?? null;
        $classes = $this->calendar->classNames(array_values(array_unique(array_column($lessons, 'class_id'))));
        $first = $lessons[0] ?? null;
        $place = $first !== null ? $this->calendar->classInForce($first['class_id']) : null;

        return [
            'day' => $day,
            'today' => $this->clock->today(),
            'now' => self::clock($this->clock),
            'away' => $away !== null && $away['away'] ? ['reason' => $away['reason']] : null,
            'morning' => $morning,
            'bells' => $place !== null ? $this->timetable->bells($place['division_id'], $place['campus_id'], $day) : [],
            'lessons' => array_map(fn (array $l): array => [
                ...$this->lessonRow($l, $rows[$l['slot_id']], $classes[$l['class_id']]['name'] ?? ''),
                'mine' => $l['teacher_id'] === $viewer->id,
                'cover_for' => $l['teacher_id'] !== $viewer->id && $l['teacher_id'] !== null ? ($names[$l['teacher_id']] ?? null) : null,
                'asked_by' => isset($askedOfMe[$l['slot_id']]) ? ($names[$askedOfMe[$l['slot_id']]] ?? null) : null,
            ], $lessons),
        ];
    }

    /**
     * Every lesson today in the classes the viewer may see, by place, and who is away.
     *
     * @return array<string, mixed>
     */
    public function today(User $viewer, string $day): array
    {
        $byPlace = [];

        foreach ($this->visible($viewer, $day) as $class) {
            if ($this->modes->takesLessons($class['division_id'])) {
                $byPlace[$class['division_id'].':'.$class['campus_id']][] = $class;
            }
        }

        $classIds = array_merge(...array_map(fn (array $cs): array => array_column($cs, 'id'), array_values($byPlace) ?: [[]]));
        $lessons = $this->timetable->lessonsOn($classIds, $day);
        $rows = $this->states($lessons, $day);
        $away = array_filter($this->awayOn($day), fn (array $a): bool => $a['away']);
        $asks = $this->asksOn($day);
        $names = $this->names([...array_filter(array_column($lessons, 'teacher_id')), ...array_keys($away), ...array_column($asks, 'user_id'),
            ...array_values(array_filter(array_map(fn (array $r): ?string => $r['register']['submitted_by'] ?? null, $rows)))]);
        $places = [];
        $totals = ['lessons' => 0, 'so_far' => 0, 'taken' => 0, 'cover' => 0, 'no_register' => 0, 'needs_cover' => 0];

        foreach ($byPlace as $classes) {
            $first = $classes[0];
            $bells = $this->timetable->bells($first['division_id'], $first['campus_id'], $day);
            $out = [];

            foreach ($classes as $class) {
                $cells = [];

                foreach (array_filter($lessons, fn (array $l): bool => $l['class_id'] === $class['id']) as $l) {
                    $row = $rows[$l['slot_id']];
                    $cells[] = [
                        ...$this->lessonRow($l, $row, $class['name']),
                        'teacher' => $l['teacher_id'] !== null ? ($names[$l['teacher_id']] ?? null) : null,
                        'taken_by' => $row['register'] !== null ? ($names[$row['register']['submitted_by']] ?? null) : null,
                        'asked' => isset($asks[$l['slot_id']]) ? ($names[$asks[$l['slot_id']]['user_id']] ?? null) : null,
                    ];
                    $totals['lessons']++;
                    $totals['so_far'] += $l['starts'] <= self::clock($this->clock) || $day < $this->clock->today() ? 1 : 0;
                    $totals[$row['state']] = ($totals[$row['state']] ?? 0) + (in_array($row['state'], ['taken', 'cover', 'no_register', 'needs_cover'], true) ? 1 : 0);
                }

                $out[] = ['id' => $class['id'], 'name' => $class['name'], 'lessons' => $cells];
            }

            $places[] = [
                'label' => ($this->school->division($first['division_id'])->name ?? '').' · '.($this->school->campus($first['campus_id'])->name ?? ''),
                'bells' => $bells,
                'classes' => $out,
            ];
        }

        $awayRows = [];

        foreach ($away as $userId => $a) {
            $theirs = array_values(array_filter($lessons, fn (array $l): bool => $l['teacher_id'] === $userId));

            if ($theirs === []) {
                continue;
            }

            $awayRows[] = [
                'user_id' => $userId,
                'name' => $names[$userId] ?? '',
                'reason' => $a['reason'],
                'marked_by' => $a['marked_by'] === $userId ? null : ($this->names([$a['marked_by']])[$a['marked_by']] ?? null),
                'marked_at' => $a['marked_at'],
                'lessons' => array_map(fn (array $l): array => [
                    ...$this->lessonRow($l, $rows[$l['slot_id']], $this->calendar->classNames([$l['class_id']])[$l['class_id']]['name'] ?? ''),
                    'asked' => isset($asks[$l['slot_id']]) ? ($names[$asks[$l['slot_id']]['user_id']] ?? null) : null,
                    'can_arrange' => $this->reach->may($viewer, AttendancePermission::Cover, $l['class_id']),
                ], $theirs),
            ];
        }

        return [
            'day' => $day,
            'today' => $this->clock->today(),
            'now' => self::clock($this->clock),
            'places' => $places,
            'away' => $awayRows,
            'totals' => ['lessons' => $totals['lessons'], 'so_far' => $totals['so_far'], 'taken' => $totals['taken'] + $totals['cover'],
                'cover' => $totals['cover'], 'no_register' => $totals['no_register'], 'needs_cover' => $totals['needs_cover']],
            'can_arrange' => $this->canArrange($viewer, $classIds),
        ];
    }

    /**
     * One lesson's register: the learners with this morning's mark and the last lesson before, and the marks once submitted.
     *
     * @return array<string, mixed>
     */
    public function lesson(User $viewer, string $slotId, string $day, Registers $registers): array
    {
        $register = DB::table('attendance_registers')->where('slot_id', $slotId)->where('day', $day)->where('kind', SubmitLessonRegister::KIND)->first();
        $lesson = $this->timetable->lesson($slotId, $day);

        // A lesson already taken keeps what it was, even if the timetable changed since.
        if ($register !== null) {
            $subject = DB::table('academic_subjects')->where('id', Row::maybeText($register, 'subject_id') ?? '')->first(['name', 'short']);
            $lesson = ['slot_id' => $slotId, 'class_id' => Row::text($register, 'class_id'), 'day' => $day, 'period' => Row::int($register, 'period'),
                'name' => Row::maybeText($register, 'period_name') ?? '', 'starts' => Row::maybeText($register, 'starts') ?? '', 'ends' => Row::maybeText($register, 'ends') ?? '',
                'subject_id' => Row::maybeText($register, 'subject_id') ?? '', 'subject' => $subject !== null ? Row::text($subject, 'name') : '',
                'subject_short' => $subject !== null ? Row::text($subject, 'short') : '', 'teacher_id' => Row::maybeText($register, 'teacher_id')];
        }

        $class = $lesson !== null ? $this->calendar->classInForce($lesson['class_id']) : null;

        if ($lesson === null || $class === null || ! $this->reach->sees($viewer, $class['id'])) {
            throw new NotFoundHttpException;
        }

        $learners = $this->learners->learnersIn($class['id'], $day);
        $registerId = $register !== null ? Row::text($register, 'id') : null;
        $morningId = DB::table('attendance_registers')->where('class_id', $class['id'])->where('day', $day)->where('kind', SubmitRegister::KIND)->value('id');
        $earlier = DB::table('attendance_registers')->where('class_id', $class['id'])->where('day', $day)->where('kind', SubmitLessonRegister::KIND)
            ->where('starts', '<', $lesson['starts'])->orderByDesc('starts')->first(['id', 'period']);
        $ids = array_values(array_filter([$registerId, is_string($morningId) ? $morningId : null, $earlier !== null ? Row::text($earlier, 'id') : null]));
        $inForce = $this->marks->inForce($ids);
        $morning = is_string($morningId) ? ($inForce[$morningId] ?? []) : [];
        $before = $earlier !== null ? ($inForce[Row::text($earlier, 'id')] ?? []) : [];
        $marks = $registerId !== null ? ($inForce[$registerId] ?? []) : [];
        $corrections = $registerId !== null ? $registers->correctionsOf($viewer, $registerId) : [];
        $mayTake = $this->reach->may($viewer, AttendancePermission::Capture, $class['id']);
        $names = $this->names(array_values(array_filter([$lesson['teacher_id'], $register !== null ? Row::text($register, 'submitted_by') : null])));
        $schoolDay = $this->days->isSchoolDay($class['division_id'], $day);

        return [
            'class' => ['id' => $class['id'], 'name' => $class['name']],
            'lesson' => ['slot_id' => $slotId, 'period' => $lesson['period'], 'name' => $lesson['name'], 'starts' => $lesson['starts'], 'ends' => $lesson['ends'],
                'subject' => $lesson['subject'], 'teacher' => $lesson['teacher_id'] !== null ? ($names[$lesson['teacher_id']] ?? null) : null],
            'day' => $day,
            'today' => $this->clock->today(),
            'school_day' => $schoolDay,
            'morning_taken' => is_string($morningId),
            'earlier' => $earlier !== null ? ['period' => Row::int($earlier, 'period')] : null,
            'register' => $register === null ? null : ['id' => Row::text($register, 'id'), 'submitted_at' => Row::text($register, 'submitted_at'),
                'submitted_by' => $names[Row::text($register, 'submitted_by')] ?? '', 'cover' => (bool) $register->cover],
            'cover' => $mayTake && $register === null && $lesson['teacher_id'] !== $viewer->id,
            'learners' => array_map(fn (array $l): array => [
                ...$l,
                'morning' => $morning[$l['id']]['status'] ?? null,
                'earlier' => $before[$l['id']]['status'] ?? null,
                'mark' => $marks[$l['id']] ?? null,
                'correction' => $corrections[$l['id']] ?? null,
            ], $learners),
            'can_take' => $mayTake && $register === null && $schoolDay && $this->modes->takesLessons($class['division_id']) && self::opened($lesson, $this->clock),
            'can_request' => $mayTake && $register !== null,
        ];
    }

    /**
     * Learners here this morning and absent from a lesson later, in the classes the viewer may see.
     *
     * @return array<string, mixed>
     */
    public function missed(User $viewer, string $day): array
    {
        $classes = [];

        foreach ($this->visible($viewer, $day) as $class) {
            if ($this->modes->takesLessons($class['division_id'])) {
                $classes[$class['id']] = $class['name'];
            }
        }

        $registers = DB::table('attendance_registers')->whereIn('class_id', array_keys($classes) ?: [Lessons::NONE])->where('day', $day)->get();
        $morningOf = [];
        $lessons = [];

        foreach ($registers as $r) {
            if (Row::text($r, 'kind') === SubmitRegister::KIND) {
                $morningOf[Row::text($r, 'class_id')] = Row::text($r, 'id');
            } else {
                $lessons[] = $r;
            }
        }

        $inForce = $this->marks->inForce(array_values(array_map(fn (object $r): string => Row::text($r, 'id'), $registers->all())));
        $first = [];

        foreach (DB::table('attendance_marks')->whereIn('register_id', array_map(fn (object $r): string => Row::text($r, 'id'), $lessons) ?: [Lessons::NONE])->whereNull('correction_id')->get(['id', 'register_id', 'learner_id']) as $m) {
            $first[Row::text($m, 'register_id')][Row::text($m, 'learner_id')] = Row::text($m, 'id');
        }

        $rows = [];

        foreach ($lessons as $r) {
            $id = Row::text($r, 'id');
            $classId = Row::text($r, 'class_id');
            $morning = isset($morningOf[$classId]) ? ($inForce[$morningOf[$classId]] ?? []) : [];

            foreach ($inForce[$id] ?? [] as $learner => $mark) {
                $was = $morning[$learner]['status'] ?? null;

                if ($mark['status'] === Mark::Absent->value && in_array($was, [Mark::Present->value, Mark::Late->value], true)) {
                    $rows[] = ['register' => $r, 'learner' => $learner, 'morning' => $was, 'mark_id' => $first[$id][$learner] ?? ''];
                }
            }
        }

        $learnerIds = array_values(array_unique(array_column($rows, 'learner')));
        $learnerNames = $this->learners->names($learnerIds);
        $about = $this->deliveries->about(LessonMissed::SUBJECT, array_values(array_filter(array_column($rows, 'mark_id'))));
        $steps = DB::table('attendance_follow_ups')->whereIn('register_id', array_map(fn (array $x): string => Row::text($x['register'], 'id'), $rows) ?: [Lessons::NONE])->orderBy('done_at')->get();
        $subjects = DB::table('academic_subjects')->whereIn('id', array_values(array_filter(array_map(fn (array $x): ?string => Row::maybeText($x['register'], 'subject_id'), $rows))) ?: [Lessons::NONE])->pluck('name', 'id')->all();
        $names = $this->names([...array_map(fn (array $x): string => Row::text($x['register'], 'submitted_by'), $rows), ...array_map(fn (object $s): string => Row::text($s, 'done_by'), $steps->all())]);
        $out = [];

        foreach ($rows as $x) {
            $r = $x['register'];
            $classId = Row::text($r, 'class_id');
            $follow = array_values(array_filter($steps->all(), fn (object $s): bool => Row::text($s, 'register_id') === Row::text($r, 'id') && Row::text($s, 'learner_id') === $x['learner']));
            $out[] = [
                'register_id' => Row::text($r, 'id'),
                'learner_id' => $x['learner'],
                'learner' => $learnerNames[$x['learner']] ?? '',
                'class' => $classes[$classId] ?? '',
                'morning' => $x['morning'],
                'subject' => is_string($subjects[Row::maybeText($r, 'subject_id') ?? ''] ?? null) ? $subjects[Row::maybeText($r, 'subject_id') ?? ''] : '',
                'period' => Row::int($r, 'period'),
                'starts' => Row::maybeText($r, 'starts') ?? '',
                'taken_by' => $names[Row::text($r, 'submitted_by')] ?? '',
                'cover' => (bool) $r->cover,
                'told' => array_map(fn (array $d): array => ['to' => $d['to'], 'channel' => $d['channel'], 'state' => $d['state'], 'at' => $d['at']], $about[$x['mark_id']] ?? []),
                'follow_ups' => array_map(fn (object $s): array => ['by' => $names[Row::text($s, 'done_by')] ?? '', 'at' => Row::text($s, 'done_at'), 'note' => Row::maybeText($s, 'note')], $follow),
                'can_follow_up' => $this->reach->may($viewer, AttendancePermission::Capture, $classId) || $this->reach->may($viewer, AttendancePermission::Correct, $classId),
            ];
        }

        usort($out, fn (array $a, array $b): int => [$a['starts'], $a['class'], $a['learner']] <=> [$b['starts'], $b['class'], $b['learner']]);

        return [
            'day' => $day,
            'today' => $this->clock->today(),
            'notice_on' => $this->messages->topicEnabled(LessonMissed::notice()->key, LessonMissed::notice()->onAtFirst()),
            'missed' => $out,
            'totals' => ['missed' => count($out), 'learners' => count($learnerIds), 'followed_up' => count(array_filter($out, fn (array $o): bool => $o['follow_ups'] !== [])),
                'told' => count(array_filter($out, fn (array $o): bool => $o['told'] !== []))],
        ];
    }

    /**
     * Who may be asked to cover a lesson: able to take the class's registers, in that day, and not teaching or covering then.
     *
     * @param  Lesson  $lesson
     * @return list<array{id: string, name: string, lessons: int}>
     */
    public function free(array $lesson): array
    {
        $holders = $this->access->holderIds(AttendancePermission::Capture, ScopeRef::of(ScopeType::ClassGroup, $lesson['class_id']));
        $away = array_keys(array_filter($this->awayOn($lesson['day']), fn (array $a): bool => $a['away']));
        $asks = $this->asksOn($lesson['day']);
        $out = [];

        foreach (DB::table('users')->whereIn('id', $holders ?: [Lessons::NONE])->where('status', 'active')->orderBy('name')->get(['id', 'name']) as $u) {
            $id = Row::text($u, 'id');

            if ($id === $lesson['teacher_id'] || in_array($id, $away, true)) {
                continue;
            }

            $theirs = $this->timetable->lessonsOf($id, $lesson['day']);

            foreach ($asks as $slot => $ask) {
                $other = $ask['user_id'] === $id && $slot !== $lesson['slot_id'] ? $this->timetable->lesson($slot, $lesson['day']) : null;

                if ($other !== null) {
                    $theirs[] = $other;
                }
            }

            $busy = array_filter($theirs, fn (array $l): bool => $l['starts'] < $lesson['ends'] && $l['ends'] > $lesson['starts']);

            if ($busy === []) {
                $out[] = ['id' => $id, 'name' => Row::text($u, 'name'), 'lessons' => count($theirs)];
            }
        }

        usort($out, fn (array $a, array $b): int => [$a['lessons'], $a['name']] <=> [$b['lessons'], $b['name']]);

        return $out;
    }

    /**
     * @param  list<Lesson>  $lessons
     * @return array<string, array{state: string, register: array{id: string, submitted_by: string, present: int, total: int}|null}>
     */
    private function states(array $lessons, string $day): array
    {
        $slots = array_column($lessons, 'slot_id');
        $registers = DB::table('attendance_registers')->whereIn('slot_id', $slots ?: [Lessons::NONE])->where('day', $day)->where('kind', SubmitLessonRegister::KIND)->get(['id', 'slot_id', 'submitted_by', 'cover'])->keyBy('slot_id');
        $marks = $this->marks->inForce(array_values(array_map(fn (object $r): string => Row::text($r, 'id'), $registers->all())));
        $away = $this->awayOn($day);
        $asks = $this->asksOn($day);
        $today = $this->clock->today();
        $now = self::clock($this->clock);
        $out = [];

        foreach ($lessons as $l) {
            $r = $registers->get($l['slot_id']);

            if (is_object($r)) {
                $statuses = array_column($marks[Row::text($r, 'id')] ?? [], 'status');
                $present = count(array_filter($statuses, fn (string $s): bool => $s !== Mark::Absent->value));
                $out[$l['slot_id']] = ['state' => (bool) $r->cover ? 'cover' : 'taken',
                    'register' => ['id' => Row::text($r, 'id'), 'submitted_by' => Row::text($r, 'submitted_by'), 'present' => $present, 'total' => count($statuses)]];

                continue;
            }

            $away_ = $l['teacher_id'] !== null && ($away[$l['teacher_id']]['away'] ?? false);
            $state = match (true) {
                $day < $today || ($day === $today && $now >= $l['ends']) => 'no_register',
                $away_ && ! isset($asks[$l['slot_id']]) => 'needs_cover',
                $day === $today && $now >= $l['starts'] => 'now',
                default => 'later',
            };
            $out[$l['slot_id']] = ['state' => $state, 'register' => null];
        }

        return $out;
    }

    /**
     * @param  Lesson  $l
     * @param  array{state: string, register: array{id: string, submitted_by: string, present: int, total: int}|null}  $row
     * @return array<string, mixed>
     */
    private function lessonRow(array $l, array $row, string $className): array
    {
        return [
            'slot_id' => $l['slot_id'], 'period' => $l['period'], 'name' => $l['name'], 'starts' => $l['starts'], 'ends' => $l['ends'],
            'class_id' => $l['class_id'], 'class' => $className, 'subject' => $l['subject'], 'subject_short' => $l['subject_short'],
            'state' => $row['state'],
            'register' => $row['register'] !== null ? ['id' => $row['register']['id'], 'present' => $row['register']['present'], 'total' => $row['register']['total']] : null,
        ];
    }

    /** @return array<string, array{away: bool, reason: string|null, marked_by: string, marked_at: string}> user id => the row in force */
    private function awayOn(string $day): array
    {
        $out = [];

        foreach (DB::table('attendance_away')->where('day', $day)->orderBy('marked_at')->get() as $row) {
            $out[Row::text($row, 'user_id')] = ['away' => (bool) $row->away, 'reason' => Row::maybeText($row, 'reason'),
                'marked_by' => Row::text($row, 'marked_by'), 'marked_at' => Row::text($row, 'marked_at')];
        }

        return $out;
    }

    /** @return array<string, array{user_id: string, asked_by: string}> slot id => the ask in force */
    private function asksOn(string $day): array
    {
        $out = [];

        foreach (DB::table('attendance_cover_asks')->where('day', $day)->orderBy('asked_at')->get() as $row) {
            $out[Row::text($row, 'slot_id')] = ['user_id' => Row::text($row, 'user_id'), 'asked_by' => Row::text($row, 'asked_by')];
        }

        return $out;
    }

    /**
     * The classes of the year whose registers the viewer may see. Someone who may see none is refused.
     *
     * @return list<array{id: string, name: string, year_id: string, division_id: string, campus_id: string, level_code: string}>
     */
    private function visible(User $viewer, string $day): array
    {
        $year = $this->calendar->currentYear($day);
        $out = array_values(array_filter($year === null ? [] : $this->calendar->classesInYear($year['id']), fn (array $c): bool => $this->reach->sees($viewer, $c['id'])));

        if ($out === []) {
            $this->access->check($viewer, AttendancePermission::Capture);
        }

        return $out;
    }

    /** @param list<string> $classIds */
    private function canArrange(User $viewer, array $classIds): bool
    {
        foreach ($classIds as $c) {
            if ($this->reach->may($viewer, AttendancePermission::Cover, $c)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        $out = [];

        foreach ($ids === [] ? [] : DB::table('users')->whereIn('id', $ids)->get(['id', 'name']) as $row) {
            $out[Row::text($row, 'id')] = Row::text($row, 'name');
        }

        return $out;
    }
}
