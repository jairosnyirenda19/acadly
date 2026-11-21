<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application\Actions;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Lectures\Application\LectureDays;
use Acadly\Modules\Lectures\Application\Passkeys;
use Acadly\Modules\Lectures\Application\RoomCode;
use Acadly\Modules\Lectures\Application\Where;
use Acadly\Modules\Lectures\Contracts\LecturesAudit;
use Acadly\Modules\Lectures\Contracts\LecturesPermission;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lecture attendance (D-102). The person giving the lecture opens it; the
 * students scan in while scanning is open; the lecturer marks by hand with a
 * reason; at the close, each student with no mark is Absent. Every mark is a
 * new row: the latest counts, and the first stays in the history.
 *
 * @phpstan-import-type Lecture from LectureDays
 */
final class SessionActions
{
    public const array MARKS = ['present', 'late', 'absent'];

    /** How early before its start a lecture may be opened, in minutes. */
    private const int EARLY = 15;

    public function __construct(
        private readonly Where $where,
        private readonly LectureDays $days,
        private readonly CourseTeaching $teaching,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** Opens attendance at a lecture being given today: scanning starts now. */
    public function open(User $by, string $divisionId, string $slotId, string $day): string
    {
        $l = $this->lecture($by, $divisionId, $slotId, $day);
        $today = $this->clock->today();
        $now = self::local($this->clock);
        if (! LectureDays::given($l, $today)) {
            throw ValidationException::withMessages(['day' => ['This lecture is not given today.']]);
        }
        $from = CarbonImmutable::parse($today.' '.$l['starts'], self::zone())->subMinutes(self::EARLY);
        $until = CarbonImmutable::parse($today.' '.$l['ends'], self::zone());
        if ($now < $from || $now >= $until) {
            throw ValidationException::withMessages(['day' => ['Attendance opens from '.$from->format('H:i').' until the lecture ends.']]);
        }
        $have = DB::table('lecture_sessions')->where('slot_id', $slotId)->where('day', $day)->value('id');
        if (is_string($have)) {
            return $have;
        }
        $id = Ids::next();
        DB::transaction(function () use ($by, $l, $id, $today): void {
            $now = $this->clock->now();
            DB::table('lecture_sessions')->insert(['id' => $id, 'division_id' => $l['division_id'], 'slot_id' => $l['slot_id'], 'day' => $l['day'], 'held_on' => $today,
                'ends' => $l['ends'], 'offering_id' => $l['offering_id'], 'opened_by' => $by->id, 'opened_at' => $now,
                'scan_until' => $now->addMinutes(RoomActions::minutes($l['division_id'])), 'secret' => bin2hex(random_bytes(32))]);
            $this->audit->record(LecturesAudit::Opened, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $l['division_id']), meta: ['session' => $id]);
        });

        return $id;
    }

    /** Closes attendance: each student with no mark is Absent. */
    public function close(User $by, string $divisionId, string $sessionId): void
    {
        $s = $this->session($by, $divisionId, $sessionId);
        if (($s->closed_at ?? null) !== null) {
            return;
        }
        DB::transaction(function () use ($by, $s): void {
            $this->finish($s, $by->id);
            $this->audit->record(LecturesAudit::Closed, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, Row::text($s, 'division_id')),
                meta: ['session' => Row::text($s, 'id')]);
        });
    }

    /** A mark by hand, with a reason: during the lecture or after, as a correction. */
    public function mark(User $by, string $divisionId, string $sessionId, string $learnerId, string $mark, string $reason): void
    {
        $s = $this->session($by, $divisionId, $sessionId);
        if (! in_array($mark, self::MARKS, true)) {
            throw ValidationException::withMessages(['mark' => ['Choose present, late or absent.']]);
        }
        $reason = trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : throw ValidationException::withMessages(['reason' => ['Give the reason.']]);
        if (! in_array($learnerId, array_column($this->teaching->students(Row::text($s, 'offering_id')), 'learner_id'), true)) {
            throw new NotFoundHttpException;
        }
        DB::transaction(function () use ($by, $s, $learnerId, $mark, $reason): void {
            DB::table('lecture_marks')->insert(['id' => Ids::next(), 'session_id' => Row::text($s, 'id'), 'learner_id' => $learnerId, 'mark' => $mark, 'source' => 'hand',
                'location' => null, 'reason' => $reason, 'by_user_id' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(LecturesAudit::HandMark, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, Row::text($s, 'division_id')), reason: $reason,
                meta: ['session' => Row::text($s, 'id'), 'learner' => $learnerId, 'mark' => $mark]);
        });
    }

    /**
     * A student scans the room code with their attendance phone.
     *
     * @param  array{credential_id: string, client_data_json: string, authenticator_data: string, signature: string}  $assertion
     * @param  array{latitude: float, longitude: float, accuracy: float}|null  $location
     * @return array{result: 'present'|'late'|'refused', why: string|null, course: string, at: string|null}
     */
    public function scan(User $student, string $code, array $assertion, ?array $location): array
    {
        $me = $this->teaching->studentOf($student->id) ?? throw new NotFoundHttpException;
        $sessionId = RoomCode::session($code);
        $s = $sessionId === null ? null : DB::table('lecture_sessions')->where('id', $sessionId)->where('division_id', $me['division_id'])->first();
        if ($s === null) {
            throw ValidationException::withMessages(['code' => ['This is not an attendance code. Scan the code on the room screen.']]);
        }
        $offering = Row::text($s, 'offering_id');
        if (! in_array($offering, $this->teaching->offeringsOf($me['learner_id'], $this->periodOf($s)), true)) {
            throw ValidationException::withMessages(['code' => ['You are not registered for this course.']]);
        }
        $course = $this->course($s);
        $learner = $me['learner_id'];
        $already = DB::table('lecture_marks')->where('session_id', Row::text($s, 'id'))->where('learner_id', $learner)->orderByDesc('created_at')->first(['mark', 'created_at']);
        if ($already !== null && Row::text($already, 'mark') !== 'absent') {
            return ['result' => Row::text($already, 'mark') === 'late' ? 'late' : 'present', 'why' => null, 'course' => $course, 'at' => self::time(Row::text($already, 'created_at'))];
        }
        $now = $this->clock->now();
        $why = match (true) {
            ($s->closed_at ?? null) !== null || $now->greaterThan(CarbonImmutable::parse(Row::text($s, 'scan_until'))) => 'closed',
            ! RoomCode::good($code, Row::text($s, 'id'), Row::text($s, 'secret'), $now->getTimestamp()) => 'code',
            default => null,
        };
        $why ??= $this->phone($learner, $code, $assertion);
        $inside = null;
        if ($why === null) {
            [$why, $inside] = $this->where($me['division_id'], $location);
        }
        if ($why !== null) {
            DB::table('lecture_refusals')->insert(['id' => Ids::next(), 'session_id' => Row::text($s, 'id'), 'learner_id' => $learner, 'why' => $why, 'created_at' => $now]);

            return ['result' => 'refused', 'why' => $why, 'course' => $course, 'at' => null];
        }
        DB::table('lecture_marks')->insert(['id' => Ids::next(), 'session_id' => Row::text($s, 'id'), 'learner_id' => $learner, 'mark' => 'present', 'source' => 'scan',
            'location' => $inside, 'reason' => null, 'by_user_id' => null, 'created_at' => $now]);

        return ['result' => 'present', 'why' => null, 'course' => $course, 'at' => self::time($now->toIso8601String())];
    }

    /** Closes every lecture whose time has ended. Run on a schedule. */
    public function settle(): int
    {
        $closed = 0;
        $today = $this->clock->today();
        $now = self::local($this->clock)->format('H:i');
        foreach (DB::table('lecture_sessions')->whereNull('closed_at')->where(fn (Builder $q) => $q->where('held_on', '<', $today)->orWhere(fn (Builder $w) => $w->where('held_on', $today)->where('ends', '<=', $now)))
            ->get() as $s) {
            DB::transaction(fn () => $this->finish($s, null));
            $closed++;
        }

        return $closed;
    }

    private function finish(object $s, ?string $by): void
    {
        $id = Row::text($s, 'id');
        $marked = DB::table('lecture_marks')->where('session_id', $id)->distinct()->pluck('learner_id')->all();
        $now = $this->clock->now();
        foreach ($this->teaching->students(Row::text($s, 'offering_id')) as $st) {
            if (! in_array($st['learner_id'], $marked, true)) {
                DB::table('lecture_marks')->insert(['id' => Ids::next(), 'session_id' => $id, 'learner_id' => $st['learner_id'], 'mark' => 'absent', 'source' => 'close',
                    'location' => null, 'reason' => null, 'by_user_id' => $by, 'created_at' => $now]);
            }
        }
        DB::table('lecture_sessions')->where('id', $id)->whereNull('closed_at')->update(['closed_at' => $now, 'closed_by' => $by]);
    }

    /**
     * Checks the phone's signature over the code. Null when it is the student's attendance phone.
     *
     * @param  array{credential_id: string, client_data_json: string, authenticator_data: string, signature: string}  $assertion
     */
    private function phone(string $learnerId, string $code, array $assertion): ?string
    {
        $phone = DB::table('lecture_phones')->where('learner_id', $learnerId)->whereNull('reset_at')->first();
        if ($phone === null || ! hash_equals(Row::text($phone, 'credential_id'), $assertion['credential_id'])) {
            return 'phone';
        }
        try {
            $count = Passkeys::verify(Row::text($phone, 'public_key'), Row::int($phone, 'sign_count'), $assertion['client_data_json'], $assertion['authenticator_data'],
                $assertion['signature'], Passkeys::encode($code));
        } catch (ValidationException) {
            return 'phone';
        }
        DB::table('lecture_phones')->where('id', Row::text($phone, 'id'))->update(['sign_count' => $count]);

        return null;
    }

    /**
     * Whether the phone is inside one of the division's campus areas. With no area set, the place is not checked.
     *
     * @param  array{latitude: float, longitude: float, accuracy: float}|null  $location
     * @return array{0: string|null, 1: string|null} why it is refused, and 'inside' or null
     */
    private function where(string $divisionId, ?array $location): array
    {
        $areas = DB::table('lecture_areas')->where('division_id', $divisionId)->whereNull('retired_at')->get(['latitude', 'longitude', 'reach']);
        if ($areas->isEmpty()) {
            return [null, null];
        }
        if ($location === null) {
            return ['location', null];
        }
        // Indoors a phone's place is often out by tens of metres: allow up to 50 metres of the accuracy it reports.
        $slack = min(max($location['accuracy'], 0.0), 50.0);
        foreach ($areas as $a) {
            if (self::metres($location['latitude'], $location['longitude'], (float) Row::text($a, 'latitude'), (float) Row::text($a, 'longitude')) - $slack <= Row::int($a, 'reach')) {
                return [null, 'inside'];
            }
        }

        return ['outside', null];
    }

    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6_371_000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * The lecture, after checking the person gives it (or covers it), or arranges cover in the division.
     *
     * @return Lecture
     */
    private function lecture(User $by, string $divisionId, string $slotId, string $day): array
    {
        $this->where->division($by, $divisionId, LecturesPermission::Teach, LecturesPermission::Cover);
        $l = $this->days->one($slotId, $day);
        if ($l === null || $l['division_id'] !== $divisionId || ! LectureDays::published($l['period_id'])) {
            throw new NotFoundHttpException;
        }
        if ($l['teacher_id'] !== $by->id && $l['lecturer_id'] !== $by->id && ! $this->where->allows($by, $divisionId, LecturesPermission::Cover)) {
            throw new AccessDeniedHttpException;
        }

        return $l;
    }

    private function session(User $by, string $divisionId, string $sessionId): object
    {
        $this->where->division($by, $divisionId, LecturesPermission::Teach, LecturesPermission::Cover);
        $s = Where::owned('lecture_sessions', $sessionId, $divisionId);
        $l = $this->days->one(Row::text($s, 'slot_id'), substr(Row::text($s, 'day'), 0, 10));
        $gives = $l !== null && ($l['teacher_id'] === $by->id || $l['lecturer_id'] === $by->id);
        if (! $gives && Row::text($s, 'opened_by') !== $by->id && ! $this->where->allows($by, $divisionId, LecturesPermission::Cover)) {
            throw new AccessDeniedHttpException;
        }

        return $s;
    }

    private function periodOf(object $s): string
    {
        $p = DB::table('lecture_slots')->where('id', Row::text($s, 'slot_id'))->value('period_id');

        return is_string($p) ? $p : '';
    }

    private function course(object $s): string
    {
        $slot = DB::table('lecture_slots')->where('id', Row::text($s, 'slot_id'))->first(['kind', 'period_id']);
        foreach ($this->teaching->offerings(Row::text($s, 'division_id'), is_object($slot) ? Row::text($slot, 'period_id') : '') as $o) {
            if ($o['id'] === Row::text($s, 'offering_id')) {
                return $o['code'].' '.(is_object($slot) ? ucfirst(Row::text($slot, 'kind')) : '');
            }
        }

        return '';
    }

    public static function zone(): string
    {
        return config()->string('acadly.timezone', 'UTC');
    }

    public static function local(Clock $clock): CarbonImmutable
    {
        return $clock->now()->setTimezone(self::zone());
    }

    private static function time(string $at): string
    {
        return CarbonImmutable::parse($at)->setTimezone(self::zone())->format('H:i');
    }
}
