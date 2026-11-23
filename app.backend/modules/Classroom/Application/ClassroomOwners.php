<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Application;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Documents\DocumentOwner;
use Acadly\Kernel\Documents\DocumentParties;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Classroom\Contracts\ClassroomDocument;
use Illuminate\Support\Facades\DB;

/**
 * The records that own Classroom's documents (D-104), and who besides the
 * permission may open them: the lecturers named on the course; a student of
 * the course for its resources and assignment files once shown; the student
 * whose hand-in it is; and that student for a marked file once released.
 */
abstract class ClassroomOwners implements DocumentOwner, DocumentParties
{
    public function __construct(
        protected readonly Courses $courses,
        protected readonly Clock $clock,
        protected readonly ClassroomDocument $kind = ClassroomDocument::Resource,
    ) {}

    /** @return class-string<DocumentOwner> */
    public static function for(ClassroomDocument $kind): string
    {
        return match ($kind) {
            ClassroomDocument::Resource => ResourceOwner::class,
            ClassroomDocument::Brief => BriefOwner::class,
            ClassroomDocument::HandIn => HandInOwner::class,
            ClassroomDocument::Feedback => FeedbackOwner::class,
        };
    }

    public function scopeOf(string $ownerId): ?ScopeRef
    {
        $row = $this->row($ownerId);

        return $row === null ? null : ScopeRef::of(ScopeType::Division, Row::text($row, 'division_id'));
    }

    public function labelOf(string $ownerId): string
    {
        $row = $this->row($ownerId);

        return $row === null ? '' : Row::text($row, 'title');
    }

    public function mayRead(User $who, string $ownerId): bool
    {
        $row = $this->row($ownerId);
        if ($row === null) {
            return false;
        }
        if ($this->lecturer($who, $row)) {
            return true;
        }
        $me = $this->courses->student($who);
        if ($me === null || ! $this->courses->holds($me['learner_id'], Row::text($row, 'offering_id'), Row::text($row, 'period_id'))) {
            return false;
        }

        return match ($this->kind) {
            ClassroomDocument::Resource => ($row->shows_from ?? null) === null || substr(Row::text($row, 'shows_from'), 0, 10) <= $this->clock->today(),
            ClassroomDocument::Brief => $this->clock->now()->greaterThanOrEqualTo(Row::text($row, 'shows_at')),
            ClassroomDocument::HandIn => Row::text($row, 'learner_id') === $me['learner_id'],
            ClassroomDocument::Feedback => Row::text($row, 'learner_id') === $me['learner_id'] && ($row->released_at ?? null) !== null,
        };
    }

    public function mayStore(User $who, string $ownerId): bool
    {
        // Only a student's own hand-in, made in the same breath as its row.
        if ($this->kind !== ClassroomDocument::HandIn) {
            return false;
        }
        $row = $this->row($ownerId);

        return $row !== null && Row::text($row, 'user_id') === $who->id && Row::text($row, 'action') === 'hand_in';
    }

    private function lecturer(User $who, object $row): bool
    {
        try {
            $course = $this->courses->find(Row::text($row, 'division_id'), Row::text($row, 'offering_id'));
        } catch (\Throwable) {
            return false;
        }

        return $this->courses->teaches($who, $course);
    }

    private function row(string $id): ?object
    {
        return match ($this->kind) {
            ClassroomDocument::Resource => DB::table('classroom_resources')->where('id', $id)->whereNull('removed_at')->first(['division_id', 'period_id', 'offering_id', 'title', 'shows_from']),
            ClassroomDocument::Brief => DB::table('classroom_tasks')->where('id', $id)->whereNull('removed_at')->first(['division_id', 'period_id', 'offering_id', 'title', 'shows_at']),
            ClassroomDocument::HandIn => DB::table('classroom_hand_ins as h')->join('classroom_tasks as t', 't.id', '=', 'h.task_id')->where('h.id', $id)
                ->first(['t.division_id', 't.period_id', 't.offering_id', 't.title', 'h.learner_id', 'h.user_id', 'h.action']),
            ClassroomDocument::Feedback => DB::table('classroom_marks as m')->join('classroom_tasks as t', 't.id', '=', 'm.task_id')->where('m.id', $id)
                ->first(['t.division_id', 't.period_id', 't.offering_id', 't.title', 'm.learner_id', 't.released_at']),
        };
    }
}
