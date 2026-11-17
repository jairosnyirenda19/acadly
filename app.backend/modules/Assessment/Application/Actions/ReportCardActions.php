<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application\Actions;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Application\Publishing;
use Acadly\Modules\Assessment\Contracts\AssessmentAudit;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes what goes on report cards (D-080): a learner's comment for the term,
 * by the class teacher or the head teacher, and the school's report card
 * settings. Both are new rows; the latest is in force.
 */
final class ReportCardActions
{
    public function __construct(
        private readonly Access $access,
        private readonly Publishing $publishing,
        private readonly EnrolmentDirectory $learners,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** An empty text takes the comment away. */
    public function comment(User $by, string $classId, string $learnerId, string $author, string $text): void
    {
        [$class, $period] = $this->publishing->place($by, $classId);

        if (! in_array($author, [Publishing::CLASS_TEACHER, Publishing::HEAD], true)) {
            throw ValidationException::withMessages(['author' => ['Choose whose comment this is.']]);
        }

        if (! $this->publishing->writes($by, $class['id'], $author) || ($author === Publishing::HEAD && ! $this->publishing->settings()['head_comment'])) {
            throw new AuthorizationException('Access is refused.');
        }

        $inClass = Ids::isValid($learnerId) && in_array($learnerId, array_column($this->learners->learnersIn($class['id'], min($this->clock->today(), $period['ends_on'])), 'id'), true);

        if (! $inClass) {
            throw ValidationException::withMessages(['learner_id' => ['This learner is not in the class.']]);
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) > Publishing::COMMENT_MAX) {
            throw ValidationException::withMessages(['text' => ['A comment can be up to '.Publishing::COMMENT_MAX.' characters.']]);
        }

        DB::transaction(function () use ($by, $class, $period, $learnerId, $author, $text): void {
            DB::table('result_comments')->insert(['id' => Ids::next(), 'learner_id' => $learnerId, 'period_id' => $period['id'], 'class_id' => $class['id'],
                'author' => $author, 'text' => $text, 'written_by' => $by->id, 'written_at' => $this->clock->now()]);
            $this->audit->record(AssessmentAudit::CommentWritten, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::ClassGroup, $class['id']),
                meta: ['learner' => $learnerId, 'period' => $period['id'], 'author' => $author, 'removed' => $text === '']);
        });
    }

    public function settings(User $by, bool $showPosition, bool $headComment): void
    {
        $this->access->check($by, AssessmentPermission::Publish, ScopeRef::institution());

        DB::transaction(function () use ($by, $showPosition, $headComment): void {
            DB::table('result_card_settings')->insert(['id' => Ids::next(), 'show_position' => $showPosition, 'head_comment' => $headComment,
                'saved_by' => $by->id, 'saved_at' => $this->clock->now()]);
            $this->audit->record(AssessmentAudit::CardSettingsSaved, actor: $this->audit->actorFor($by), at: ScopeRef::institution(),
                meta: ['show_position' => $showPosition, 'head_comment' => $headComment]);
        });
    }
}
