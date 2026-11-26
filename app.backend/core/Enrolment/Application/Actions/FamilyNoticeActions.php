<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Application\FamilyNotices;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Notifications\FamilyNoticeSent;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Publishing and withdrawing notices to families (D-115). A notice is never
 * changed; a mistake is withdrawn with a reason and a new one published. The
 * text message, when asked for, is the office's own short version, sent once
 * to each family when the notice is published now.
 *
 * @phpstan-type NoticeInput array{audience: string, division_id: string|null, level_code: string|null, class_id: string|null, title: string, body: string, sms_text: string|null, pinned_until: string|null, publish_at: string|null}
 */
final class FamilyNoticeActions
{
    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly SchoolDirectory $directory,
        private readonly SchoolProfile $school,
        private readonly Notifier $notifier,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** The characters left for the office's words, after the school's name that every text starts with. */
    public function textRoom(): int
    {
        return 160 - mb_strlen($this->school->name().': ');
    }

    /**
     * @param  NoticeInput  $input
     * @return string the notice's id
     */
    public function publish(User $by, array $input): string
    {
        $audience = $input['audience'];
        $division = $input['division_id'];
        $class = null;

        if (! in_array($audience, FamilyNotices::AUDIENCES, true)) {
            throw ValidationException::withMessages(['audience' => ['Choose who the notice is for.']]);
        }

        if ($audience === 'class') {
            $class = $input['class_id'] !== null ? $this->calendar->classInForce($input['class_id']) : null;
            $division = $class['division_id'] ?? null;

            if ($class === null) {
                throw ValidationException::withMessages(['class_id' => ['Choose a class of this year.']]);
            }
        }

        if (in_array($audience, ['division', 'level'], true) && ($division === null || ! Ids::isValid($division) || $this->directory->division($division) === null)) {
            throw ValidationException::withMessages(['division_id' => ['Choose the division.']]);
        }

        $level = $audience === 'level' ? $input['level_code'] : null;

        if ($audience === 'level' && ($level === null || ! array_key_exists($level, $this->levels((string) $division)))) {
            throw ValidationException::withMessages(['level_code' => ['Choose a level of the division.']]);
        }

        $at = FamilyNotices::scopeOf($audience, $division, $class['id'] ?? null);
        $this->access->check($by, EnrolmentPermission::Notify, $at);

        $title = trim($input['title']);
        $body = trim($input['body']);
        $text = $input['sms_text'] !== null && trim($input['sms_text']) !== '' ? trim($input['sms_text']) : null;
        $now = CarbonImmutable::parse($this->clock->now());
        $publishAt = $input['publish_at'] !== null ? CarbonImmutable::parse($input['publish_at']) : $now;
        $errors = [];

        if ($title === '' || mb_strlen($title) > 120) {
            $errors['title'] = ['Give a title of up to 120 characters.'];
        }
        if ($body === '' || mb_strlen($body) > 4000) {
            $errors['body'] = ['Write the notice, up to 4,000 characters.'];
        }
        if ($text !== null && mb_strlen($text) > $this->textRoom()) {
            $errors['sms_text'] = ["The text message can have {$this->textRoom()} characters after the school's name."];
        }
        if ($text !== null && $publishAt->greaterThan($now->addMinute())) {
            $errors['sms_text'] = ['A text message is sent when the notice is published now. Publish now, or leave the text out.'];
        }
        if ($input['pinned_until'] !== null && $input['pinned_until'] < $this->clock->today()) {
            $errors['pinned_until'] = ['Keep it at the top until today or a later day.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $id = Ids::next();

        DB::transaction(function () use ($by, $id, $audience, $division, $level, $class, $title, $body, $text, $input, $publishAt, $at, $now): void {
            DB::table('family_notices')->insert(['id' => $id, 'audience' => $audience, 'division_id' => $audience === 'school' ? null : $division, 'level_code' => $level,
                'class_id' => $class['id'] ?? null, 'title' => $title, 'body' => $body, 'sms_text' => $text, 'pinned_until' => $input['pinned_until'],
                'publish_at' => $publishAt->max($now), 'author_id' => $by->id, 'created_at' => $now]);
            $this->audit->record(EnrolmentAudit::NoticePublished, actor: $this->audit->actorFor($by), at: $at, meta: ['notice' => $id, 'audience' => $audience, 'text' => $text !== null]);

            if ($text !== null) {
                $this->notifier->send(new FamilyNoticeSent($id, $title, $text), $by);
            }
        });

        return $id;
    }

    public function withdraw(User $by, string $id, string $reason): void
    {
        $row = Ids::isValid($id) ? DB::table('family_notices')->where('id', $id)->first() : null;

        if ($row === null) {
            throw new NotFoundHttpException;
        }

        $a = FamilyNotices::audienceOf($row);
        $at = FamilyNotices::scopeOf($a['audience'], $a['division_id'], $a['class_id']);

        if (! $this->access->allows($by, EnrolmentPermission::Notify, $at)) {
            throw new NotFoundHttpException;
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => ['Say why the notice is withdrawn, in up to 255 characters.']]);
        }
        if (DB::table('family_notice_withdrawals')->where('notice_id', $id)->exists()) {
            throw ValidationException::withMessages(['reason' => ['This notice is already withdrawn.']]);
        }

        DB::transaction(function () use ($by, $id, $reason, $at): void {
            DB::table('family_notice_withdrawals')->insert(['notice_id' => $id, 'reason' => $reason, 'withdrawn_by' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(EnrolmentAudit::NoticeWithdrawn, actor: $this->audit->actorFor($by), at: $at, reason: $reason, meta: ['notice' => $id]);
        });
    }

    /** @return array<string, string> level code => label */
    public function levels(string $divisionId): array
    {
        $levels = $this->directory->division($divisionId)?->profile->levels ?? [];

        return array_column($levels, 'label', 'code');
    }
}
