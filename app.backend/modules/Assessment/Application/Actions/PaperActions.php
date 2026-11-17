<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application\Actions;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Documents\Actions\StoreDocument;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Application\Reach;
use Acadly\Modules\Assessment\Application\Sheets;
use Acadly\Modules\Assessment\Contracts\AssessmentAudit;
use Acadly\Modules\Assessment\Contracts\AssessmentDocument;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Domain\Assessment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Keeps learners' marked papers (the approved design). Optional: a page at a
 * time, photographed or scanned, by the teacher whose assessment it is. The
 * file is a kernel document, checked and scanned like every upload; the row
 * here says whose paper it is and which page. A page removed stays recorded.
 */
final class PaperActions
{
    public const int MAX_PAGES = 12;

    public function __construct(
        private readonly Sheets $sheets,
        private readonly Reach $reach,
        private readonly EnrolmentDirectory $learners,
        private readonly StoreDocument $store,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function add(User $by, string $assessmentId, string $learnerId, UploadedFile $file): string
    {
        $a = $this->writable($by, $assessmentId);

        if (! in_array($learnerId, array_column($this->learners->learnersIn($a->class_id, $a->heldOn()), 'id'), true)) {
            throw ValidationException::withMessages(['learner_id' => ['This learner is not in the class for this assessment.']]);
        }

        $pages = DB::table('assessment_papers')->where('assessment_id', $a->id)->where('learner_id', $learnerId)->whereNull('removed_at')->count();

        if ($pages >= self::MAX_PAGES) {
            throw ValidationException::withMessages(['file' => ['A paper has up to '.self::MAX_PAGES.' pages.']]);
        }

        $document = $this->store->handle($by, AssessmentDocument::Paper, $a->id, $file, 'Marked paper');
        $id = Ids::next();

        DB::transaction(function () use ($by, $a, $learnerId, $document, $id, $pages): void {
            DB::table('assessment_papers')->insert(['id' => $id, 'assessment_id' => $a->id, 'learner_id' => $learnerId, 'document_id' => $document->id,
                'position' => $pages + 1, 'added_by' => $by->id, 'added_at' => $this->clock->now()]);
            $this->audit->record(AssessmentAudit::PaperAdded, subject: $a, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::ClassGroup, $a->class_id),
                aboutPersonId: $learnerId, meta: ['learner' => $learnerId, 'document' => $document->id, 'page' => $pages + 1]);
        });

        return $id;
    }

    /** Removes a page; the pages after it move up. */
    public function remove(User $by, string $paperId): void
    {
        $page = $this->page($by, $paperId);
        $now = $this->clock->now();

        DB::transaction(function () use ($by, $page, $now): void {
            DB::table('assessment_papers')->where('id', Row::text($page, 'id'))->update(['removed_at' => $now, 'removed_by' => $by->id]);
            DB::table('assessment_papers')->where('assessment_id', Row::text($page, 'assessment_id'))->where('learner_id', Row::text($page, 'learner_id'))
                ->whereNull('removed_at')->where('position', '>', Row::int($page, 'position'))->decrement('position');
            $this->changed($by, $page, 'removed');
        });
    }

    /** Moves a page one place earlier or later. */
    public function move(User $by, string $paperId, bool $earlier): void
    {
        $page = $this->page($by, $paperId);
        $to = Row::int($page, 'position') + ($earlier ? -1 : 1);
        $other = DB::table('assessment_papers')->where('assessment_id', Row::text($page, 'assessment_id'))->where('learner_id', Row::text($page, 'learner_id'))
            ->whereNull('removed_at')->where('position', $to)->value('id');

        if (! is_string($other)) {
            return;
        }

        DB::transaction(function () use ($by, $page, $to, $other): void {
            DB::table('assessment_papers')->where('id', $other)->update(['position' => Row::int($page, 'position')]);
            DB::table('assessment_papers')->where('id', Row::text($page, 'id'))->update(['position' => $to]);
            $this->changed($by, $page, 'moved');
        });
    }

    private function writable(User $by, string $assessmentId): Assessment
    {
        $a = $this->sheets->find($by, $assessmentId);

        if (! $this->reach->owns($by, $a) || ! $this->reach->may($by, AssessmentPermission::Enter, $a->class_id)) {
            throw new AuthorizationException('Only the teacher of this assessment adds or changes its papers.');
        }

        return $a;
    }

    private function page(User $by, string $paperId): object
    {
        $page = Ids::isValid($paperId) ? DB::table('assessment_papers')->where('id', $paperId)->whereNull('removed_at')->first() : null;

        if ($page === null) {
            throw new NotFoundHttpException;
        }

        $this->writable($by, Row::text($page, 'assessment_id'));

        return $page;
    }

    private function changed(User $by, object $page, string $what): void
    {
        $a = Assessment::query()->findOrFail(Row::text($page, 'assessment_id'));
        $this->audit->record(AssessmentAudit::PaperChanged, subject: $a, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::ClassGroup, $a->class_id),
            aboutPersonId: Row::text($page, 'learner_id'), meta: ['learner' => Row::text($page, 'learner_id'), 'page' => Row::int($page, 'position'), 'what' => $what]);
    }
}
