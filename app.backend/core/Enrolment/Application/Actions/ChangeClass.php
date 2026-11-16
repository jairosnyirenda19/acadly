<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Application\LearnerDetails;
use Acadly\Core\Enrolment\Application\LearnerPlaces;
use Acadly\Core\Enrolment\Application\Reach;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a learner to another class of the same level at the same campus in
 * the same year (09 §3, a class change: a plain Action). The open placement
 * ends the day before; a new one opens on the day given. Both in one
 * transaction. A move to another campus is a transfer, not this.
 */
final class ChangeClass
{
    public function __construct(
        private readonly Reach $reach,
        private readonly LearnerPlaces $places,
        private readonly AcademicCalendar $calendar,
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function handle(User $by, string $id, string $classId, string $from, string $reason): void
    {
        ['learner' => $learner, 'at' => $at] = $this->reach->learner($by, $id, EnrolmentPermission::Manage);
        $place = $this->places->current($learner->id);
        $target = $this->calendar->classInForce($classId);
        $from = LearnerDetails::day($from, 'from', 'The date of the move');

        if ($place === null || ! $place['open']) {
            throw ValidationException::withMessages(['class_id' => ['This learner is not in a class now.']]);
        }

        if ($target === null || $target['id'] === $place['class_id'] || $target['year_id'] !== $place['year_id']
            || $target['campus_id'] !== $place['campus_id'] || $target['level_code'] !== $place['level_code']) {
            throw ValidationException::withMessages(['class_id' => ['Choose another class of the same level at the same campus, this year.']]);
        }

        $this->access->check($by, EnrolmentPermission::Manage, ScopeRef::of(ScopeType::ClassGroup, $target['id']));

        if ($from <= $place['starts_on'] || $from > $this->clock->today()) {
            throw ValidationException::withMessages(['from' => ['The move must be dated after '.$place['starts_on'].' and not in the future.']]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Say why the learner is moved.']]);
        }

        DB::transaction(function () use ($by, $learner, $place, $target, $from, $reason, $at): void {
            $now = $this->clock->now();
            $closed = DB::table('placements')->where('id', $place['id'])->whereNull('ends_on')
                ->update(['ends_on' => date('Y-m-d', (int) strtotime($from.' -1 day')), 'exit_reason' => 'class_change', 'closed_by' => $by->id]);

            if ($closed !== 1) {
                throw ValidationException::withMessages(['class_id' => ['The learner was moved by someone else. Open the record again.']]);
            }

            DB::table('placements')->insert(['id' => Ids::next(), 'enrolment_id' => $place['enrolment_id'], 'learner_id' => $learner->id,
                'year_id' => $target['year_id'], 'division_id' => $target['division_id'], 'campus_id' => $target['campus_id'],
                'level_code' => $target['level_code'], 'class_id' => $target['id'], 'starts_on' => $from, 'entry_reason' => 'class_change',
                'reason' => mb_substr(trim($reason), 0, 255), 'opened_by' => $by->id, 'created_at' => $now]);

            $this->audit->record(EnrolmentAudit::ClassChanged, subject: $learner, reason: $reason, actor: $this->audit->actorFor($by), at: $at,
                meta: ['from_class' => $place['class_id'], 'to_class' => $target['id'], 'from' => $from]);
        });
    }
}
