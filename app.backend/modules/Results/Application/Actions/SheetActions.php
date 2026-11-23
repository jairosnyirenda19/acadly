<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application\Actions;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Acadly\Modules\Results\Application\Sheets;
use Acadly\Modules\Results\Contracts\ResultsAudit;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Acadly\Modules\Results\Domain\Sheet;
use Acadly\Modules\Results\Journeys\Sheet\OutcomeId;
use Acadly\Modules\Results\Journeys\Sheet\State;
use Acadly\Modules\Results\Journeys\Sheet\StepId;
use Acadly\Modules\Results\Notifications\ResultsReleased;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Writing results (D-105): exam marks, the sheet's journey from entry to
 * release, bars lifted, absences accepted, changes after release and the
 * supplementary sitting. Marks are rows, append-only: the latest counts.
 *
 * @phpstan-import-type Course from Sheets
 */
final class SheetActions
{
    public function __construct(
        private readonly Sheets $sheets,
        private readonly CourseTeaching $teaching,
        private readonly JourneyEngine $engine,
        private readonly Blueprints $blueprints,
        private readonly Notifier $notifier,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** The sheet of a course and sitting, opened on first use for the main sitting. */
    public function ensure(User $by, string $divisionId, string $offeringId, string $sitting): Sheet
    {
        $sheet = $this->sheets->sheet($offeringId, $sitting);
        if ($sheet !== null) {
            return $sheet;
        }
        if ($sitting !== 'main') {
            throw new NotFoundHttpException;
        }
        $course = $this->sheets->find($divisionId, $offeringId);

        return $this->create($by, $course, 'main', null);
    }

    /**
     * Saves exam marks or absences entered by the course's lecturer.
     *
     * @param  list<array{learner_id: string, exam: float|null, absent: bool}>  $marks
     */
    public function enter(User $by, string $divisionId, string $offeringId, string $sitting, array $marks, ?string $examOn): int
    {
        $course = $this->sheets->open($by, $divisionId, $offeringId);
        if (! $this->sheets->teaches($by, $course)) {
            throw new AccessDeniedHttpException;
        }
        $sheet = $this->ensure($by, $divisionId, $offeringId, $sitting);
        if ($sheet->state !== State::Entered->value) {
            throw ValidationException::withMessages(['marks' => ['These marks were sent for moderation. Moderation may return them to you.']]);
        }
        $students = array_column($this->sheets->students($course, $sitting), 'learner_id');
        $current = Sheets::marks($sheet->id);
        $now = $this->clock->now();
        $rows = [];
        foreach ($marks as $i => $m) {
            if (! in_array($m['learner_id'], $students, true)) {
                throw ValidationException::withMessages(["marks.{$i}.learner_id" => ['This student does not sit this paper.']]);
            }
            $exam = $m['absent'] ? null : $m['exam'];
            if ($exam !== null && ($exam < 0 || $exam > 100 || round($exam, 1) !== $exam)) {
                throw ValidationException::withMessages(["marks.{$i}.exam" => ['Give a mark from 0 to 100, with at most one decimal place.']]);
            }
            $absence = $m['absent'] ? 'absent' : null;
            $was = $current[$m['learner_id']] ?? null;
            if ($exam === null && $absence === null && $was === null) {
                continue;
            }
            if ($was !== null && $was['exam'] === $exam && $was['absence'] === $absence) {
                continue;
            }
            $rows[] = ['id' => Ids::next(), 'sheet_id' => $sheet->id, 'learner_id' => $m['learner_id'], 'exam' => $exam, 'absence' => $absence, 'stage' => 'entry',
                'reason' => null, 'by_user_id' => $by->id, 'created_at' => $now];
        }

        DB::transaction(function () use ($by, $sheet, $rows, $examOn): void {
            if ($examOn !== null) {
                $sheet->forceFill(['exam_on' => $examOn])->save();
            }
            if ($rows !== []) {
                DB::table('results_marks')->insert($rows);
                $this->audit->record(ResultsAudit::MarksEntered, subject: $sheet, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $sheet->division_id),
                    meta: ['marks' => count($rows)]);
            }
        });

        return count($rows);
    }

    /** Sends a sheet for moderation: the continuous assessment and attendance are frozen as they are now. */
    public function submit(User $by, string $divisionId, string $offeringId, string $sitting): void
    {
        $course = $this->sheets->open($by, $divisionId, $offeringId);
        if (! $this->sheets->teaches($by, $course)) {
            throw new AccessDeniedHttpException;
        }
        $sheet = $this->ensure($by, $divisionId, $offeringId, $sitting);
        DB::transaction(function () use ($by, $sheet, $course): void {
            $now = $this->clock->now();
            $rows = [];
            foreach ($this->sheets->lines($sheet, $course) as $l) {
                $rows[] = ['id' => Ids::next(), 'sheet_id' => $sheet->id, 'learner_id' => $l['learner_id'], 'ca' => $l['ca'], 'ca_share' => $l['share'],
                    'held' => $l['held'], 'attended' => $l['attended'], 'barred' => $l['barred'] || $l['result'] === 'barred', 'created_at' => $now];
            }
            $this->engine->perform($sheet, $by, StepId::Submit, OutcomeId::Submitted);
            if ($rows !== []) {
                DB::table('results_lines')->insert($rows);
            }
        });
    }

    /**
     * Changes marks in moderation, each with a reason.
     *
     * @param  list<array{learner_id: string, exam: float|null, reason: string}>  $changes
     */
    public function moderate(User $by, string $divisionId, string $sheetId, array $changes): void
    {
        [$sheet, $course] = $this->sheetOf($by, $divisionId, $sheetId);
        $this->sheets->must($by, $course, ResultsPermission::Moderate);
        if ($sheet->state !== State::Submitted->value) {
            throw ValidationException::withMessages(['changes' => ['Marks change in moderation only while the sheet waits for moderation.']]);
        }
        $this->change($by, $sheet, $course, $changes, 'moderation', ResultsAudit::MarkModerated);
    }

    /** Passes a sheet to the board, or returns it to the lecturer with a reason. */
    public function decideModeration(User $by, string $divisionId, string $sheetId, bool $pass, ?string $reason): void
    {
        [$sheet] = $this->sheetOf($by, $divisionId, $sheetId);
        DB::transaction(fn () => $this->engine->perform($sheet, $by, StepId::Moderate, $pass ? OutcomeId::Moderated : OutcomeId::Returned, $pass ? [] : ['reason' => $reason]));
    }

    /** Lifts the attendance bar for one student of a course, with a reason. */
    public function liftBar(User $by, string $divisionId, string $offeringId, string $learnerId, string $reason): void
    {
        $course = $this->sheets->open($by, $divisionId, $offeringId);
        $this->sheets->must($by, $course, ResultsPermission::Moderate);
        $main = $this->sheets->sheet($offeringId, 'main');
        if ($main !== null && in_array($main->state, [State::Approved->value, State::Released->value], true)) {
            throw ValidationException::withMessages(['reason' => ['The board has approved these results. A change now is a board decision.']]);
        }
        if (! in_array($learnerId, array_column($this->teaching->students($offeringId), 'learner_id'), true)) {
            throw new NotFoundHttpException;
        }
        if (DB::table('results_bar_lifts')->where('offering_id', $offeringId)->where('learner_id', $learnerId)->exists()) {
            throw ValidationException::withMessages(['reason' => ['The bar is already lifted for this student.']]);
        }
        DB::transaction(function () use ($by, $course, $offeringId, $learnerId, $reason): void {
            DB::table('results_bar_lifts')->insert(['id' => Ids::next(), 'offering_id' => $offeringId, 'learner_id' => $learnerId, 'reason' => $reason, 'by_user_id' => $by->id,
                'created_at' => $this->clock->now()]);
            $this->audit->record(ResultsAudit::BarLifted, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $course['division_id']), reason: $reason,
                aboutPersonId: $learnerId, meta: ['offering' => $offeringId]);
        });
    }

    /** The board accepts a student's absence from the exam: the exam is deferred to the supplementary sitting. */
    public function defer(User $by, string $divisionId, string $sheetId, string $learnerId, string $reason): void
    {
        [$sheet, $course] = $this->sheetOf($by, $divisionId, $sheetId);
        $this->sheets->must($by, $course, ResultsPermission::Board);
        if ($sheet->state === State::Released->value || $sheet->sitting !== 'main') {
            throw ValidationException::withMessages(['reason' => ['An exam is deferred only before the main sitting\'s results are released.']]);
        }
        if (! in_array($learnerId, array_column($this->sheets->students($course, 'main'), 'learner_id'), true)) {
            throw new NotFoundHttpException;
        }
        DB::transaction(function () use ($by, $sheet, $learnerId, $reason): void {
            DB::table('results_marks')->insert(['id' => Ids::next(), 'sheet_id' => $sheet->id, 'learner_id' => $learnerId, 'exam' => null, 'absence' => 'deferred', 'stage' => 'board',
                'reason' => $reason, 'by_user_id' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(ResultsAudit::Deferred, subject: $sheet, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $sheet->division_id),
                reason: $reason, aboutPersonId: $learnerId);
        });
    }

    /**
     * The board approves every moderated sheet of a programme and year.
     *
     * @return int the sheets approved
     */
    public function approveCohort(User $by, string $divisionId, string $periodId, string $cohortId): int
    {
        $this->sheets->enter($by, $divisionId);
        if (! $this->sheets->holds($by, ResultsPermission::Board, $divisionId)) {
            throw new AccessDeniedHttpException;
        }
        $cohort = $this->cohort($divisionId, $periodId, $cohortId);
        $behind = Sheet::query()->whereIn('offering_id', $cohort['offerings'])->where('sitting', 'main')->whereIn('state', [State::Entered->value, State::Submitted->value])->count()
            + count(array_diff($cohort['offerings'], array_values(array_filter(Sheet::query()->whereIn('offering_id', $cohort['offerings'])->where('sitting', 'main')->pluck('offering_id')->all(), 'is_string'))));
        if ($behind > 0) {
            throw ValidationException::withMessages(['cohort' => [$behind === 1 ? '1 course of this programme and year is not moderated yet.' : "{$behind} courses of this programme and year are not moderated yet."]]);
        }
        $sheets = Sheet::query()->whereIn('offering_id', $cohort['offerings'])->where('state', State::Moderated->value)->get();
        if ($sheets->isEmpty()) {
            throw ValidationException::withMessages(['cohort' => ['No course of this programme and year waits for the board.']]);
        }
        DB::transaction(function () use ($by, $sheets): void {
            foreach ($sheets as $s) {
                $this->engine->perform($s, $by, StepId::Approve, OutcomeId::Approved);
            }
        });

        return $sheets->count();
    }

    /** The board returns one sheet for moderation, with a reason. */
    public function returnToModeration(User $by, string $divisionId, string $sheetId, string $reason): void
    {
        [$sheet] = $this->sheetOf($by, $divisionId, $sheetId);
        DB::transaction(fn () => $this->engine->perform($sheet, $by, StepId::Approve, OutcomeId::Returned, ['reason' => $reason]));
    }

    /**
     * Releases every approved sheet of a semester's sitting to its students.
     *
     * @return int the sheets released
     */
    public function release(User $by, string $divisionId, string $periodId, string $sitting, bool $sms): int
    {
        $this->sheets->enter($by, $divisionId);
        if (! $this->sheets->holds($by, ResultsPermission::Release, $divisionId)) {
            throw new AccessDeniedHttpException;
        }
        $period = $this->sheets->period($divisionId, $periodId);
        $sheets = Sheet::query()->where('division_id', $divisionId)->where('period_id', $period['id'])->where('sitting', $sitting)->where('state', State::Approved->value)->get();
        if ($sheets->isEmpty()) {
            throw ValidationException::withMessages(['release' => ['No results of this sitting are approved by the board.']]);
        }
        $id = Ids::next();
        DB::transaction(function () use ($by, $divisionId, $period, $sitting, $sms, $sheets, $id): void {
            $now = $this->clock->now();
            foreach ($sheets as $s) {
                $this->engine->perform($s, $by, StepId::Release, OutcomeId::Released);
                $s->forceFill(['released_at' => $now, 'release_id' => $id])->save();
            }
            DB::table('results_releases')->insert(['id' => $id, 'division_id' => $divisionId, 'period_id' => $period['id'], 'sitting' => $sitting, 'sheets' => $sheets->count(),
                'sms' => $sms, 'released_by' => $by->id, 'released_at' => $now]);
            $this->audit->record(ResultsAudit::Released, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId),
                meta: ['release' => $id, 'sheets' => $sheets->count(), 'sitting' => $sitting, 'sms' => $sms]);
            if ($sms) {
                $this->notifier->send(new ResultsReleased($id, $divisionId, $period['name'], $sitting), $by);
            }
        });

        return $sheets->count();
    }

    /** A change to a released result: a board decision, with a reason. The student sees the new result and a new slip. */
    public function correct(User $by, string $divisionId, string $sheetId, string $learnerId, ?float $exam, string $reason): void
    {
        [$sheet, $course] = $this->sheetOf($by, $divisionId, $sheetId);
        $this->sheets->must($by, $course, ResultsPermission::Board);
        if ($sheet->state !== State::Released->value) {
            throw ValidationException::withMessages(['exam' => ['Before release, marks change in moderation.']]);
        }
        $this->change($by, $sheet, $course, [['learner_id' => $learnerId, 'exam' => $exam, 'reason' => $reason]], 'correction', ResultsAudit::Corrected);
    }

    /**
     * Opens the supplementary sitting of a semester: a sheet for every course with students to sit again or sit deferred exams.
     *
     * @return int the sheets opened
     */
    public function prepareSupplementary(User $by, string $divisionId, string $periodId, ?string $examOn): int
    {
        $this->sheets->enter($by, $divisionId);
        if (! $this->sheets->holds($by, ResultsPermission::Release, $divisionId)) {
            throw new AccessDeniedHttpException;
        }
        $period = $this->sheets->period($divisionId, $periodId);
        $opened = 0;
        DB::transaction(function () use ($by, $divisionId, $period, $examOn, &$opened): void {
            foreach ($this->sheets->offerings($divisionId, $period['id']) as $o) {
                $course = ['offering' => $o, 'period' => $period, 'division_id' => $divisionId];
                if ($this->sheets->sheet($o['id'], 'supplementary') !== null || $this->sheets->students($course, 'supplementary') === []) {
                    continue;
                }
                $this->create($by, $course, 'supplementary', $examOn);
                $opened++;
            }
            $this->audit->record(ResultsAudit::SupplementaryPrepared, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId),
                meta: ['period' => $period['id'], 'sheets' => $opened]);
        });

        return $opened;
    }

    /**
     * A programme and year of a semester, with the offerings of its courses.
     *
     * @return array{id: string, label: string, offerings: list<string>}
     */
    public function cohort(string $divisionId, string $periodId, string $cohortId): array
    {
        foreach ($this->teaching->cohorts($divisionId, $periodId) as $c) {
            if ($c['id'] === $cohortId) {
                return $c;
            }
        }
        throw new NotFoundHttpException;
    }

    /** @return array{0: Sheet, 1: Course} */
    public function sheetOf(User $by, string $divisionId, string $sheetId): array
    {
        $sheet = Sheet::query()->where('id', $sheetId)->where('division_id', $divisionId)->first() ?? throw new NotFoundHttpException;

        return [$sheet, $this->sheets->open($by, $divisionId, $sheet->offering_id)];
    }

    /** @param  Course  $course */
    private function create(User $by, array $course, string $sitting, ?string $examOn): Sheet
    {
        $sheet = new Sheet;
        $sheet->forceFill(['division_id' => $course['division_id'], 'period_id' => $course['period']['id'], 'offering_id' => $course['offering']['id'], 'sitting' => $sitting,
            'state' => State::Entered->value, 'journey_ref' => (string) $this->blueprints->newest('results.sheet'), 'exam_on' => $examOn, 'created_by' => $by->id])->save();

        return $sheet;
    }

    /**
     * @param  Course  $course
     * @param  list<array{learner_id: string, exam: float|null, reason: string}>  $changes
     */
    private function change(User $by, Sheet $sheet, array $course, array $changes, string $stage, ResultsAudit $action): void
    {
        $students = array_column($this->sheets->students($course, $sheet->sitting), 'learner_id');
        foreach ($changes as $i => $c) {
            if (! in_array($c['learner_id'], $students, true)) {
                throw ValidationException::withMessages(["changes.{$i}.learner_id" => ['This student does not sit this paper.']]);
            }
            if ($c['exam'] !== null && ($c['exam'] < 0 || $c['exam'] > 100 || round($c['exam'], 1) !== $c['exam'])) {
                throw ValidationException::withMessages(["changes.{$i}.exam" => ['Give a mark from 0 to 100, with at most one decimal place.']]);
            }
            if (trim($c['reason']) === '') {
                throw ValidationException::withMessages(["changes.{$i}.reason" => ['Give the reason for the change.']]);
            }
        }
        DB::transaction(function () use ($by, $sheet, $changes, $stage, $action): void {
            $now = $this->clock->now();
            foreach ($changes as $c) {
                DB::table('results_marks')->insert(['id' => Ids::next(), 'sheet_id' => $sheet->id, 'learner_id' => $c['learner_id'], 'exam' => $c['exam'],
                    'absence' => $c['exam'] === null ? 'absent' : null, 'stage' => $stage, 'reason' => trim($c['reason']), 'by_user_id' => $by->id, 'created_at' => $now]);
                $this->audit->record($action, subject: $sheet, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $sheet->division_id),
                    reason: trim($c['reason']), aboutPersonId: $c['learner_id']);
            }
        });
    }
}
