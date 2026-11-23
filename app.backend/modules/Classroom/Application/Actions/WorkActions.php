<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Application\Actions;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Documents\Actions\StoreDocument;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Classroom\Application\Courses;
use Acadly\Modules\Classroom\Application\Submissions;
use Acadly\Modules\Classroom\Contracts\ClassroomAudit;
use Acadly\Modules\Classroom\Contracts\ClassroomDocument;
use Carbon\CarbonImmutable;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hand-ins and marks (D-104). A student hands in and withdraws their own
 * work; each is a new row. The lecturer marks the work in force at the
 * deadline (or the late hand-in), with feedback and a marked file; marks
 * reach the students when the lecturer releases them, together, once every
 * hand-in is marked and the time for late hand-ins is over. A change after
 * release needs a reason, and is a new row.
 */
final class WorkActions
{
    public function __construct(
        private readonly Courses $courses,
        private readonly StoreDocument $store,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @param  list<UploadedFile>  $files */
    public function handIn(User $student, string $taskId, array $files): string
    {
        [$task, $me] = $this->studentTask($student, $taskId);
        $now = $this->clock->now();
        $standing = Submissions::standing($task, Submissions::events($taskId, [$me['learner_id']])[$me['learner_id']] ?? [], $now);
        if (! $standing['can_hand_in']) {
            throw ValidationException::withMessages(['files' => [match ($standing['state']) {
                'handed_in' => 'You have handed in. Withdraw it first to hand in again.',
                'not_open' => 'This is not open yet.',
                default => 'Hand-ins for this are closed.',
            }]]);
        }
        $accepts = json_decode(Row::text($task, 'accepts'), true);
        $accepts = is_array($accepts) ? $accepts : [];
        $errors = [];
        if ($files === [] || count($files) > Row::int($task, 'max_files')) {
            $errors['files'] = ['Hand in from 1 to '.Row::int($task, 'max_files').' files.'];
        }
        $info = new finfo(FILEINFO_MIME_TYPE);
        foreach ($files as $i => $f) {
            $mime = $info->file((string) $f->getRealPath());
            if (! in_array($mime, $accepts, true)) {
                $errors["files.{$i}"] = ['"'.$f->getClientOriginalName().'" is not a kind of file this takes.'];
            }
            if ($f->getSize() > Row::int($task, 'max_mb') * 1024 * 1024) {
                $errors["files.{$i}"] = ['"'.$f->getClientOriginalName().'" is larger than '.Row::int($task, 'max_mb').' MB.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $late = $now->greaterThan(CarbonImmutable::parse(Row::text($task, 'due_at')));
        $id = Ids::next();

        DB::transaction(function () use ($student, $task, $me, $files, $id, $late, $now): void {
            DB::table('classroom_hand_ins')->insert(['id' => $id, 'task_id' => Row::text($task, 'id'), 'learner_id' => $me['learner_id'], 'user_id' => $student->id,
                'action' => 'hand_in', 'late' => $late, 'created_at' => $now]);
            foreach ($files as $f) {
                $this->store->handle($student, ClassroomDocument::HandIn, $id, $f);
            }
            $this->audit->record(ClassroomAudit::HandedIn, actor: $this->audit->actorFor($student), at: ScopeRef::of(ScopeType::Division, Row::text($task, 'division_id')),
                meta: ['task' => Row::text($task, 'id'), 'hand_in' => $id, 'late' => $late]);
        });

        return $id;
    }

    public function withdraw(User $student, string $taskId): void
    {
        [$task, $me] = $this->studentTask($student, $taskId);
        $now = $this->clock->now();
        $standing = Submissions::standing($task, Submissions::events($taskId, [$me['learner_id']])[$me['learner_id']] ?? [], $now);
        if (! $standing['can_withdraw']) {
            throw ValidationException::withMessages(['files' => ['There is nothing to withdraw, or the deadline has passed.']]);
        }
        DB::transaction(function () use ($student, $task, $me, $now): void {
            DB::table('classroom_hand_ins')->insert(['id' => Ids::next(), 'task_id' => Row::text($task, 'id'), 'learner_id' => $me['learner_id'], 'user_id' => $student->id,
                'action' => 'withdraw', 'late' => false, 'created_at' => $now]);
            $this->audit->record(ClassroomAudit::Withdrawn, actor: $this->audit->actorFor($student), at: ScopeRef::of(ScopeType::Division, Row::text($task, 'division_id')),
                meta: ['task' => Row::text($task, 'id')]);
        });
    }

    /** Marks the work a student handed in, with feedback and an optional marked file. */
    public function mark(User $by, string $divisionId, string $taskId, string $learnerId, float $mark, ?string $feedback, ?string $reason, ?UploadedFile $file): string
    {
        $task = $this->task($by, $divisionId, $taskId);
        $now = $this->clock->now();
        $standing = Submissions::standing($task, Submissions::events($taskId, [$learnerId])[$learnerId] ?? [], $now);
        if ($standing['final'] === null) {
            throw ValidationException::withMessages(['mark' => [$standing['state'] === 'handed_in' || $standing['state'] === 'open' ? 'Marking opens at the deadline.' : 'There is no hand-in to mark.']]);
        }
        if ($mark < 0 || $mark > Row::int($task, 'out_of')) {
            throw ValidationException::withMessages(['mark' => ['Give a mark from 0 to '.Row::int($task, 'out_of').'.']]);
        }
        $released = ($task->released_at ?? null) !== null;
        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null;
        if ($released && $reason === null) {
            throw ValidationException::withMessages(['reason' => ['The marks are released: give the reason for the change.']]);
        }
        $feedback = $feedback !== null && trim($feedback) !== '' ? mb_substr(trim($feedback), 0, 10000) : null;
        $id = Ids::next();

        DB::transaction(function () use ($by, $task, $learnerId, $mark, $feedback, $reason, $file, $id, $released, $now): void {
            DB::table('classroom_marks')->insert(['id' => $id, 'task_id' => Row::text($task, 'id'), 'learner_id' => $learnerId, 'mark' => round($mark, 2), 'feedback' => $feedback,
                'reason' => $reason, 'by_user_id' => $by->id, 'created_at' => $now]);
            if ($file !== null) {
                $this->store->handle($by, ClassroomDocument::Feedback, $id, $file);
            }
            $this->audit->record($released ? ClassroomAudit::MarkChanged : ClassroomAudit::Marked, actor: $this->audit->actorFor($by),
                at: ScopeRef::of(ScopeType::Division, Row::text($task, 'division_id')), reason: $reason, meta: ['task' => Row::text($task, 'id'), 'learner' => $learnerId]);
        });

        return $id;
    }

    /** Releases the marks of a task to its students. */
    public function release(User $by, string $divisionId, string $taskId): void
    {
        $task = $this->task($by, $divisionId, $taskId);
        if (($task->released_at ?? null) !== null) {
            return;
        }
        $now = $this->clock->now();
        if (! Submissions::over($task, $now)) {
            throw ValidationException::withMessages(['release' => ['Hand-ins are still open. Release the marks once the time for late hand-ins is over.']]);
        }
        $marks = Submissions::marks($taskId);
        $waiting = 0;
        foreach (Submissions::events($taskId) as $learner => $events) {
            if (Submissions::standing($task, $events, $now)['final'] !== null && ! isset($marks[$learner])) {
                $waiting++;
            }
        }
        if ($waiting > 0) {
            throw ValidationException::withMessages(['release' => [$waiting === 1 ? '1 hand-in is not marked yet.' : "{$waiting} hand-ins are not marked yet."]]);
        }
        DB::transaction(function () use ($by, $task, $now): void {
            DB::table('classroom_tasks')->where('id', Row::text($task, 'id'))->update(['released_at' => $now, 'released_by' => $by->id]);
            $this->audit->record(ClassroomAudit::Released, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, Row::text($task, 'division_id')),
                meta: ['task' => Row::text($task, 'id')]);
        });
    }

    /** A task of a course the person runs. */
    private function task(User $by, string $divisionId, string $taskId): object
    {
        $task = DB::table('classroom_tasks')->where('id', $taskId)->where('division_id', $divisionId)->whereNull('removed_at')->first() ?? throw new NotFoundHttpException;
        $this->courses->run($by, $divisionId, Row::text($task, 'offering_id'));

        return $task;
    }

    /**
     * A task of a course the student holds a place in, shown to students.
     *
     * @return array{0: object, 1: array{learner_id: string, division_id: string}}
     */
    private function studentTask(User $student, string $taskId): array
    {
        $me = $this->courses->student($student) ?? throw new NotFoundHttpException;
        $task = DB::table('classroom_tasks')->where('id', $taskId)->where('division_id', $me['division_id'])->whereNull('removed_at')->first();
        if ($task === null || ! $this->courses->holds($me['learner_id'], Row::text($task, 'offering_id'), Row::text($task, 'period_id'))) {
            throw new NotFoundHttpException;
        }

        return [$task, $me];
    }
}
