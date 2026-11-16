<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Application\AcadlyId;
use Acadly\Core\Enrolment\Application\Contacts;
use Acadly\Core\Enrolment\Application\Duplicates;
use Acadly\Core\Enrolment\Application\LearnerDetails;
use Acadly\Core\Enrolment\Application\Numbers;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Domain\Learner;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\Watch;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admits a learner (the approved Admit screen): one learner record with a
 * new Acadly ID, the school's admission number, an active stay, a placement
 * in a class from the day admitted, and at least two contacts. A possible
 * duplicate (same names and date of birth) is refused until the person
 * confirms it is a different learner: records are never merged by a guess.
 */
final class AdmitLearner
{
    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly Duplicates $duplicates,
        private readonly Numbers $numbers,
        private readonly Contacts $contacts,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array{first_name: string, other_names: string|null, surname: string, sex: string, date_of_birth: string, class_id: string, admitted_on: string, admission_number: string|null, different: bool}  $input
     * @param  list<array{name: string, relationship: string, phone: string, email: string|null, notices_by?: string, main: bool}>  $contacts
     */
    public function handle(User $by, array $input, array $contacts): Learner
    {
        $class = $this->calendar->classInForce($input['class_id']);
        $this->access->check($by, EnrolmentPermission::Admit, $class !== null ? ScopeRef::of(ScopeType::ClassGroup, $class['id']) : ScopeRef::institution());

        if ($class === null) {
            throw ValidationException::withMessages(['class_id' => ['Choose a class in force.']]);
        }

        $at = ScopeRef::of(ScopeType::ClassGroup, $class['id']);

        $details = LearnerDetails::check($input, $this->clock->today());
        $admitted = LearnerDetails::day($input['admitted_on'], 'admitted_on', 'The date admitted');
        $year = DB::table('academic_years')->where('id', $class['year_id'])->first(['starts_on', 'ends_on']);

        if ($admitted > $this->clock->today()) {
            throw ValidationException::withMessages(['admitted_on' => ['The date admitted cannot be in the future.']]);
        }

        if ($year !== null && $admitted > substr(Row::text($year, 'ends_on'), 0, 10)) {
            throw ValidationException::withMessages(['admitted_on' => ['The date admitted is after the end of the class\'s year.']]);
        }

        $people = $this->contacts->check($contacts);
        $number = $this->numbers->checkOrNext($input['admission_number']);

        if (! $input['different']) {
            $same = $this->duplicates->like($details['first_name'], $details['surname'], $details['date_of_birth']);

            if ($same !== null) {
                throw ValidationException::withMessages($this->duplicates->refusal($by, $same));
            }
        }

        return DB::transaction(function () use ($by, $details, $class, $at, $admitted, $people, $number, $input): Learner {
            $now = $this->clock->now();
            $learner = Learner::query()->create([...$details, 'acadly_id' => AcadlyId::next()]);
            $this->numbers->issue($by, $learner->id, $number);

            $enrolment = Ids::next();
            DB::table('enrolments')->insert(['id' => $enrolment, 'learner_id' => $learner->id, 'status' => 'active', 'admitted_on' => $admitted,
                'admitted_by' => $by->id, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('placements')->insert(['id' => Ids::next(), 'enrolment_id' => $enrolment, 'learner_id' => $learner->id,
                'year_id' => $class['year_id'], 'division_id' => $class['division_id'], 'campus_id' => $class['campus_id'],
                'level_code' => $class['level_code'], 'class_id' => $class['id'], 'starts_on' => $admitted, 'entry_reason' => 'admission',
                'opened_by' => $by->id, 'created_at' => $now]);
            $this->contacts->add($by, $learner->id, $people);

            $this->audit->record(EnrolmentAudit::LearnerAdmitted, subject: $learner, changes: Watch::created($learner)->diff(),
                actor: $this->audit->actorFor($by), at: $at,
                meta: ['admission_number' => $number, 'admitted_on' => $admitted, 'contacts' => count($people), 'confirmed_different' => $input['different']]);

            return $learner;
        });
    }
}
