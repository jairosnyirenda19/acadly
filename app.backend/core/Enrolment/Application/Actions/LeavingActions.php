<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Application\LearnerDetails;
use Acadly\Core\Enrolment\Application\LearnerPlaces;
use Acadly\Core\Enrolment\Application\Reach;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Contracts\Events\LearnerLeft;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Events\Outbox;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A learner leaves during the year, and may come back (D-114). Leaving ends
 * the placement on the last day and the enrolment as "left", with the reason;
 * from the next day the learner is on no register, list or reminder. The
 * record, marks and history stay. Coming back opens a new enrolment and a
 * placement; history keeps both.
 */
final class LeavingActions
{
    public const array KINDS = ['moved_school' => 'Moved to another school', 'moved_away' => 'Family moved away', 'other' => 'Left for another reason'];

    /** How far ahead a last day may be recorded. */
    private const int AHEAD_DAYS = 60;

    public function __construct(
        private readonly Reach $reach,
        private readonly LearnerPlaces $places,
        private readonly AcademicCalendar $calendar,
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Outbox $outbox,
        private readonly Clock $clock,
    ) {}

    public function leave(User $by, string $id, string $lastDay, string $kind, string $destination, string $note, bool $waive): void
    {
        ['learner' => $learner, 'at' => $at] = $this->reach->learner($by, $id, EnrolmentPermission::Manage);
        $place = $this->places->current($learner->id);
        $lastDay = LearnerDetails::day($lastDay, 'last_day', 'The last day');
        $destination = trim($destination);
        $note = trim($note);
        $today = $this->clock->today();

        if ($place === null || ! $place['open']) {
            throw ValidationException::withMessages(['last_day' => ['This learner is not in a class now.']]);
        }
        if ($lastDay < $place['starts_on'] || $lastDay > CarbonImmutable::parse($today)->addDays(self::AHEAD_DAYS)->toDateString()) {
            throw ValidationException::withMessages(['last_day' => ['The last day must be on or after '.$place['starts_on'].', and no more than '.self::AHEAD_DAYS.' days ahead.']]);
        }
        if (! isset(self::KINDS[$kind])) {
            throw ValidationException::withMessages(['kind' => ['Choose why the learner leaves.']]);
        }
        if ($kind === 'other' && $note === '') {
            throw ValidationException::withMessages(['note' => ['Say why the learner leaves.']]);
        }
        if (mb_strlen($destination) > 160 || mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['note' => ['The school can be up to 160 characters and the note up to 500.']]);
        }

        DB::transaction(function () use ($by, $learner, $place, $lastDay, $kind, $destination, $note, $waive, $at): void {
            $now = $this->clock->now();
            $closed = DB::table('placements')->where('id', $place['id'])->whereNull('ends_on')
                ->update(['ends_on' => $lastDay, 'exit_reason' => 'left', 'reason' => mb_substr(self::KINDS[$kind].($note !== '' ? ': '.$note : ''), 0, 255), 'closed_by' => $by->id]);

            if ($closed !== 1) {
                throw ValidationException::withMessages(['last_day' => ['The learner was moved by someone else. Open the record again.']]);
            }

            DB::table('enrolments')->where('id', $place['enrolment_id'])->where('status', 'active')
                ->update(['status' => 'left', 'ended_on' => $lastDay, 'end_reason' => 'left', 'updated_at' => $now]);
            DB::table('learner_departures')->insert(['id' => Ids::next(), 'learner_id' => $learner->id, 'placement_id' => $place['id'], 'last_day' => $lastDay,
                'kind' => $kind, 'destination' => $destination !== '' ? $destination : null, 'note' => $note !== '' ? $note : null, 'waive_asked' => $waive,
                'recorded_by' => $by->id, 'created_at' => $now]);
            $this->audit->record(EnrolmentAudit::LearnerLeft, subject: $learner, reason: self::KINDS[$kind].($note !== '' ? ': '.$note : ''),
                actor: $this->audit->actorFor($by), at: $at, meta: ['last_day' => $lastDay, 'kind' => $kind, 'waive_asked' => $waive]);
            $this->outbox->record(new LearnerLeft($learner->id, $lastDay, $waive, $by->id));
        });
    }

    public function return(User $by, string $id, string $classId, string $from): void
    {
        ['learner' => $learner, 'at' => $at] = $this->reach->learner($by, $id, EnrolmentPermission::Manage);
        $place = $this->places->current($learner->id);
        $class = $this->calendar->classInForce($classId);
        $from = LearnerDetails::day($from, 'from', 'The first day back');
        $left = DB::table('learner_departures')->where('learner_id', $learner->id)->max('last_day');

        if (($place !== null && $place['open']) || DB::table('enrolments')->where('learner_id', $learner->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['class_id' => ['This learner is in a class now.']]);
        }
        if ($class === null || $class['year_id'] !== ($this->calendar->currentYear()['id'] ?? null)) {
            throw ValidationException::withMessages(['class_id' => ['Choose a class of this school year.']]);
        }

        $this->access->check($by, EnrolmentPermission::Manage, ScopeRef::of(ScopeType::ClassGroup, $class['id']));

        if ((is_string($left) && $from <= $left) || $from > $this->clock->today()) {
            throw ValidationException::withMessages(['from' => ['The first day back must be after the last day at school, and not in the future.']]);
        }

        DB::transaction(function () use ($by, $learner, $class, $from, $at): void {
            $now = $this->clock->now();
            $enrolment = Ids::next();
            DB::table('enrolments')->insert(['id' => $enrolment, 'learner_id' => $learner->id, 'status' => 'active', 'admitted_on' => $from,
                'admitted_by' => $by->id, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('placements')->insert(['id' => Ids::next(), 'enrolment_id' => $enrolment, 'learner_id' => $learner->id,
                'year_id' => $class['year_id'], 'division_id' => $class['division_id'], 'campus_id' => $class['campus_id'],
                'level_code' => $class['level_code'], 'class_id' => $class['id'], 'starts_on' => $from, 'entry_reason' => 'returned',
                'opened_by' => $by->id, 'created_at' => $now]);
            $this->audit->record(EnrolmentAudit::LearnerReturned, subject: $learner, actor: $this->audit->actorFor($by), at: $at,
                meta: ['class' => $class['id'], 'from' => $from]);
        });
    }
}
