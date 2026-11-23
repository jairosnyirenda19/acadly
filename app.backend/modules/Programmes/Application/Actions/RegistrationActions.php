<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application\Actions;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Application\Place;
use Acadly\Modules\Programmes\Application\Registration\FeesClearance;
use Acadly\Modules\Programmes\Application\Registration\RegistrationChecks;
use Acadly\Modules\Programmes\Application\Registration\Windows;
use Acadly\Modules\Programmes\Application\StudentProgressWriter;
use Acadly\Modules\Programmes\Contracts\ProgrammesAudit;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Acadly\Modules\Programmes\Contracts\StudentStatus;
use Acadly\Modules\Programmes\Domain\Registration;
use Acadly\Modules\Programmes\Journeys\Registration\OutcomeId;
use Acadly\Modules\Programmes\Journeys\Registration\State;
use Acadly\Modules\Programmes\Journeys\Registration\StepId;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Semester registration (D-098). Every change of state goes through the
 * journey engine. Starting a semester opens a registration for every active
 * student at once, with the curriculum's courses filled in; one with nothing
 * to choose and every check passing goes straight to approval.
 */
final class RegistrationActions
{
    public function __construct(
        private readonly Place $place,
        private readonly JourneyEngine $engine,
        private readonly Blueprints $blueprints,
        private readonly RegistrationChecks $checks,
        private readonly FeesClearance $fees,
        private readonly Windows $windows,
        private readonly StudentActions $students,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @param  array{opens_on: string, closes_on: string, late_until: string, add_drop_until: string, late_fee: int, fees_share: int}  $w */
    public function saveWindow(User $by, string $divisionId, string $periodId, array $w): void
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Register);
        $this->place->period($divisionId, $periodId);

        if (! ($w['opens_on'] <= $w['closes_on'] && $w['closes_on'] <= $w['late_until'] && $w['late_until'] <= $w['add_drop_until'])) {
            throw ValidationException::withMessages(['closes_on' => ['The dates must follow in order: opens, closes, late registration ends, add and drop ends.']]);
        }

        if ($w['fees_share'] < 0 || $w['fees_share'] > 100 || $w['late_fee'] < 0) {
            throw ValidationException::withMessages(['fees_share' => ['The share of fees is from 0 to 100 per cent.']]);
        }

        DB::transaction(function () use ($by, $divisionId, $periodId, $w): void {
            $now = $this->clock->now();
            $values = ['opens_on' => $w['opens_on'], 'closes_on' => $w['closes_on'], 'late_until' => $w['late_until'], 'add_drop_until' => $w['add_drop_until'],
                'late_fee_minor' => $w['late_fee'], 'fees_share' => $w['fees_share'], 'updated_at' => $now];

            if (DB::table('programme_registration_windows')->where('period_id', $periodId)->exists()) {
                DB::table('programme_registration_windows')->where('period_id', $periodId)->update($values);
            } else {
                DB::table('programme_registration_windows')->insert([...$values, 'id' => Ids::next(), 'division_id' => $divisionId, 'period_id' => $periodId, 'created_at' => $now]);
            }

            $this->audit->record(ProgrammesAudit::WindowSaved, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId), meta: ['period' => $periodId, ...$w]);
        });
    }

    /**
     * Opens a registration for every active student in the division who has none for the semester.
     *
     * @return array{opened: int, sent: int, completed: int}
     */
    public function startSemester(User $by, string $divisionId, string $periodId): array
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Register);
        $period = $this->place->period($divisionId, $periodId);

        if (Windows::of($periodId) === null) {
            throw ValidationException::withMessages(['period' => ['Set the registration dates of the semester first.']]);
        }

        $yearId = Row::text(DB::table('academic_periods')->where('id', $periodId)->first(['year_id']) ?? throw new NotFoundHttpException, 'year_id');
        $out = ['opened' => 0, 'sent' => 0, 'completed' => 0];
        $already = DB::table('programme_registrations')->where('period_id', $periodId)->pluck('learner_id')->all();

        foreach (DB::table('programme_students as s')->join('programmes as p', 'p.id', '=', 's.programme_id')->where('s.division_id', $divisionId)
            ->whereNull('s.ended_at')->where('s.status', StudentStatus::Active->value)->whereNotIn('s.learner_id', $already)
            ->orderBy('s.created_at')->get(['s.*', 'p.years as programme_years']) as $student) {
            $studentId = Row::text($student, 'id');
            $year = Row::int($student, 'year_of_study');

            // A new academic year: a student who was registered in their year last academic year moves up a year.
            if ($period['position'] === 1 && DB::table('programme_registrations as r')->join('academic_periods as ap', 'ap.id', '=', 'r.period_id')
                ->where('r.learner_id', Row::text($student, 'learner_id'))->where('r.state', State::Registered->value)
                ->where('r.year_of_study', $year)->where('ap.year_id', '<>', $yearId)->exists()) {
                if ($year >= Row::int($student, 'programme_years')) {
                    $this->students->changeTrusted($by, $divisionId, $studentId, StudentStatus::Completed, null, 'Registered in the final year of the curriculum.');
                    $out['completed']++;

                    continue;
                }
                $year++;
                $studentId = $this->students->changeTrusted($by, $divisionId, $studentId, StudentStatus::Active, $year, "Moved to Year {$year}.");
            }

            $r = $this->build($by, $divisionId, $periodId, $studentId, Row::text($student, 'learner_id'), Row::text($student, 'curriculum_id'), $year, $period['position']);
            $out['opened']++;

            if ($this->noChoiceLeft($r) && $this->checks->run($r)['problems'] === [] && in_array($this->windows->phase($periodId), ['open', 'late'], true)) {
                $this->submit($by, $divisionId, $r->id);
                $out['sent']++;
            }
        }

        DB::table('programme_registration_windows')->where('period_id', $periodId)->update(['started_at' => $this->clock->now(), 'started_by' => $by->id]);
        $this->audit->record(ProgrammesAudit::SemesterStarted, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId), meta: ['period' => $periodId, ...$out]);

        return $out;
    }

    /** Opens one student's registration for the semester, with the curriculum's courses filled in. */
    public function open(User $by, string $divisionId, string $periodId, string $learnerId): string
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Register);
        $period = $this->place->period($divisionId, $periodId);
        $student = DB::table('programme_students')->where('division_id', $divisionId)->where('learner_id', $learnerId)->whereNull('ended_at')
            ->where('status', StudentStatus::Active->value)->first() ?? throw ValidationException::withMessages(['learner_id' => ['The student is not active in a programme.']]);

        if (DB::table('programme_registrations')->where('period_id', $periodId)->where('learner_id', $learnerId)->exists()) {
            throw ValidationException::withMessages(['learner_id' => ['The student has a registration for this semester already.']]);
        }

        return $this->build($by, $divisionId, $periodId, Row::text($student, 'id'), $learnerId, Row::text($student, 'curriculum_id'), Row::int($student, 'year_of_study'), $period['position'])->id;
    }

    /**
     * Sets the courses of a registration that is being chosen: one group of each course.
     *
     * @param  list<string>  $offeringIds
     */
    public function saveCourses(User $by, string $divisionId, string $registrationId, array $offeringIds): void
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Register);
        $this->writeCourses($by, $divisionId, $this->registration($divisionId, $registrationId), $offeringIds);
    }

    /**
     * The student chooses their own courses (D-101), while registration is open and the
     * registration is theirs and still being chosen. No permission: a person acts on their own record.
     *
     * @param  list<string>  $offeringIds
     */
    public function saveOwnCourses(User $student, Registration $r, array $offeringIds): void
    {
        if ($r->heldBy() !== $student->id || ! in_array($this->windows->phase($r->period_id), ['open', 'late'], true)) {
            throw ValidationException::withMessages(['offerings' => ['Registration is not open for changes now.']]);
        }
        $this->writeCourses($student, $r->division_id, $r, $offeringIds);
    }

    /** The student sends their own registration for approval (D-101): the journey checks it is theirs. */
    public function submitOwn(User $student, Registration $r): void
    {
        $late = $this->windows->phase($r->period_id) === 'late';

        DB::transaction(function () use ($student, $r, $late): void {
            $this->engine->perform($r, $student, StepId::SubmitOwn, OutcomeId::Submitted);
            if ($late && ! $r->late) {
                DB::table('programme_registrations')->where('id', $r->id)->update(['late' => true]);
            }
        });
    }

    /** @param  list<string>  $offeringIds */
    private function writeCourses(User $by, string $divisionId, Registration $r, array $offeringIds): void
    {
        if ($r->state !== State::Draft->value) {
            throw ValidationException::withMessages(['offerings' => ['The courses change only while they are being chosen.']]);
        }

        $offerings = DB::table('programme_offerings as o')->join('programme_courses as c', 'c.id', '=', 'o.course_id')
            ->where('o.division_id', $divisionId)->where('o.period_id', $r->period_id)->whereIn('o.id', array_values(array_unique($offeringIds)))
            ->get(['o.id', 'o.course_id', 'c.credits']);

        if ($offerings->count() !== count(array_unique($offeringIds)) || $offerings->pluck('course_id')->unique()->count() !== $offerings->count()) {
            throw ValidationException::withMessages(['offerings' => ['Choose one group of each course, from this semester\'s offerings.']]);
        }

        DB::transaction(function () use ($by, $divisionId, $r, $offerings): void {
            DB::table('programme_registration_courses')->where('registration_id', $r->id)->where('version', $r->version)->delete();
            $this->insertCourses($r, array_values(array_map(fn (object $o): array => ['offering_id' => Row::text($o, 'id'), 'course_id' => Row::text($o, 'course_id'),
                'credits' => Row::int($o, 'credits')], $offerings->all())));
            $this->audit->record(ProgrammesAudit::CoursesChosen, subject: $r, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId),
                meta: ['courses' => $offerings->count(), 'version' => $r->version]);
        });
    }

    public function submit(User $by, string $divisionId, string $registrationId): void
    {
        $r = $this->registration($divisionId, $registrationId);
        $late = $this->windows->phase($r->period_id) === 'late';

        DB::transaction(function () use ($by, $r, $late): void {
            $this->engine->perform($r, $by, StepId::Submit, OutcomeId::Submitted);
            if ($late && ! $r->late) {
                DB::table('programme_registrations')->where('id', $r->id)->update(['late' => true]);
            }
        });
    }

    /**
     * Approves registrations; each whose fees are cleared is registered at once when the person may.
     *
     * @param  list<string>  $ids
     * @return array{approved: int, registered: int}
     */
    public function approve(User $by, string $divisionId, array $ids): array
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Approve);
        $out = ['approved' => 0, 'registered' => 0];

        foreach (array_values(array_unique($ids)) as $id) {
            $r = $this->registration($divisionId, $id);
            DB::transaction(fn () => $this->engine->perform($r, $by, StepId::Approve, OutcomeId::Approved));
            $out['approved']++;
            $out['registered'] += $this->tryRegister($by, $r) ? 1 : 0;
        }

        return $out;
    }

    public function returnBack(User $by, string $divisionId, string $registrationId, string $reason): void
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Approve);
        $r = $this->registration($divisionId, $registrationId);
        DB::transaction(fn () => $this->engine->perform($r, $by, StepId::Approve, OutcomeId::Returned, ['reason' => $reason]));
    }

    /**
     * Completes the registration of each approved one whose fees are now cleared.
     *
     * @param  list<string>|null  $ids  null for every approved registration of the semester
     */
    public function register(User $by, string $divisionId, string $periodId, ?array $ids): int
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::Register);
        $q = Registration::query()->where('division_id', $divisionId)->where('period_id', $periodId)->where('state', State::Approved->value);
        if ($ids !== null) {
            $q->whereIn('id', $ids);
        }

        $done = 0;
        foreach ($q->get() as $r) {
            $done += $this->tryRegister($by, $r) ? 1 : 0;
        }

        return $done;
    }

    public function allowFees(User $by, string $divisionId, string $registrationId, string $reason): void
    {
        $this->place->division($by, $divisionId, ProgrammesPermission::AllowFees);
        $r = $this->registration($divisionId, $registrationId);

        if (! in_array($r->state, [State::Draft->value, State::Submitted->value, State::Approved->value], true) || $r->fees_allowed_by !== null) {
            throw ValidationException::withMessages(['reason' => ['This registration does not wait for fees.']]);
        }

        DB::transaction(function () use ($by, $divisionId, $r, $reason): void {
            $r->forceFill(['fees_allowed_by' => $by->id, 'fees_allowed_reason' => $reason, 'fees_allowed_at' => $this->clock->now()])->save();
            $this->audit->record(ProgrammesAudit::FeesAllowed, subject: $r, reason: $reason, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId));
        });

        $this->tryRegister($by, $r);
    }

    /** Reopens a registered student's courses in add and drop, as a new version; the earlier version stays. */
    public function change(User $by, string $divisionId, string $registrationId, string $reason): void
    {
        $r = $this->registration($divisionId, $registrationId);

        DB::transaction(function () use ($by, $r, $reason): void {
            $this->engine->perform($r, $by, StepId::Change, OutcomeId::Reopened, ['reason' => $reason]);
            $rows = RegistrationChecks::courses($r);
            DB::table('programme_registrations')->where('id', $r->id)->update(['version' => $r->version + 1]);
            $r->version++;
            $r->syncOriginalAttribute('version');
            $this->insertCourses($r, array_map(fn (array $c): array => ['offering_id' => $c['offering_id'], 'course_id' => $c['course_id'], 'credits' => $c['credits']], $rows));
        });
    }

    public function withdraw(User $by, string $divisionId, string $registrationId, string $reason): void
    {
        $r = $this->registration($divisionId, $registrationId);
        DB::transaction(fn () => $this->engine->perform($r, $by, StepId::Withdraw, OutcomeId::Withdrawn, ['reason' => $reason]));
    }

    public function registration(string $divisionId, string $id): Registration
    {
        $r = Registration::query()->where('division_id', $divisionId)->find($id);

        return $r instanceof Registration ? $r : throw new NotFoundHttpException;
    }

    private function tryRegister(User $by, Registration $r): bool
    {
        $r->refresh();

        if ($r->state !== State::Approved->value || ! $this->fees->of($r)['cleared'] || ! $this->place->allows($by, $r->division_id, ProgrammesPermission::Register)) {
            return false;
        }

        DB::transaction(fn () => $this->engine->perform($r, $by, StepId::Register, OutcomeId::Registered));

        return true;
    }

    private function build(User $by, string $divisionId, string $periodId, string $studentId, string $learnerId, string $curriculumId, int $year, int $semester): Registration
    {
        $spot = RegistrationChecks::spot($curriculumId, $year, $semester);
        $courses = $spot['core'];
        foreach ($spot['groups'] as $g) {
            if (count($g['courses']) === $g['choose']) {
                $courses = [...$courses, ...$g['courses']];
            }
        }

        // D-106: courses carried from an earlier year join as Repeat when this semester offers them.
        $courses = array_values(array_unique([...$courses, ...StudentProgressWriter::carried($learnerId)]));

        $chosen = [];
        foreach ($courses as $courseId) {
            $offering = $this->offeringWithRoom($periodId, $courseId);
            if ($offering !== null) {
                $chosen[] = $offering;
            }
        }

        return DB::transaction(function () use ($by, $divisionId, $periodId, $studentId, $learnerId, $year, $semester, $chosen): Registration {
            $r = new Registration;
            $r->forceFill(['division_id' => $divisionId, 'period_id' => $periodId, 'learner_id' => $learnerId, 'student_id' => $studentId, 'year_of_study' => $year,
                'semester' => $semester, 'state' => State::Draft->value, 'journey_ref' => (string) $this->blueprints->newest('programmes.registration'), 'late' => false,
                'version' => 1, 'opened_by' => $by->id])->save();
            $this->insertCourses($r, $chosen);
            $this->audit->record(ProgrammesAudit::RegistrationOpened, subject: $r, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId),
                meta: ['courses' => count($chosen)]);

            return $r;
        });
    }

    /**
     * The first group of a course, by letter, that still has room, counting
     * registrations being chosen too, so a semester's students spread over its groups.
     *
     * @return array{offering_id: string, course_id: string, credits: int}|null
     */
    private function offeringWithRoom(string $periodId, string $courseId): ?array
    {
        $groups = DB::table('programme_offerings as o')->join('programme_courses as c', 'c.id', '=', 'o.course_id')->where('o.period_id', $periodId)->where('o.course_id', $courseId)
            ->orderBy('o.group_label')->get(['o.id', 'o.size_limit', 'c.credits']);

        foreach ($groups as $o) {
            $limit = ($o->size_limit ?? null) !== null ? Row::int($o, 'size_limit') : null;
            $count = DB::table('programme_registration_courses as rc')->join('programme_registrations as r', function (JoinClause $j): void {
                $j->on('r.id', '=', 'rc.registration_id')->on('r.version', '=', 'rc.version');
            })->where('rc.offering_id', Row::text($o, 'id'))->where('r.state', '<>', State::Withdrawn->value)->count();

            if ($limit === null || $count < $limit) {
                return ['offering_id' => Row::text($o, 'id'), 'course_id' => $courseId, 'credits' => Row::int($o, 'credits')];
            }
        }

        return null;
    }

    /** @param  list<array{offering_id: string, course_id: string, credits: int}>  $courses */
    private function insertCourses(Registration $r, array $courses): void
    {
        $curriculum = Row::text(DB::table('programme_students')->where('id', $r->student_id)->first(['curriculum_id']) ?? throw new NotFoundHttpException, 'curriculum_id');
        $spot = RegistrationChecks::spot($curriculum, $r->year_of_study, $r->semester);
        $groupOf = [];
        foreach ($spot['groups'] as $g) {
            foreach ($g['courses'] as $c) {
                $groupOf[$c] = $g['id'];
            }
        }

        foreach ($courses as $c) {
            $kind = in_array($c['course_id'], $spot['core'], true) ? 'core' : (isset($groupOf[$c['course_id']]) ? 'elective' : 'repeat');
            DB::table('programme_registration_courses')->insert(['id' => Ids::next(), 'registration_id' => $r->id, 'version' => $r->version, 'offering_id' => $c['offering_id'],
                'course_id' => $c['course_id'], 'kind' => $kind, 'elective_group_id' => $kind === 'elective' ? $groupOf[$c['course_id']] : null, 'credits' => $c['credits']]);
        }
    }

    private function noChoiceLeft(Registration $r): bool
    {
        $curriculum = Row::text(DB::table('programme_students')->where('id', $r->student_id)->first(['curriculum_id']) ?? throw new NotFoundHttpException, 'curriculum_id');

        foreach (RegistrationChecks::spot($curriculum, $r->year_of_study, $r->semester)['groups'] as $g) {
            if (count($g['courses']) !== $g['choose']) {
                return false;
            }
        }

        return true;
    }
}
