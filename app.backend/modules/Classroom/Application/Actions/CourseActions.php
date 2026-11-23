<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Application\Actions;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Documents\Actions\StoreDocument;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Classroom\Application\Courses;
use Acadly\Modules\Classroom\Contracts\ClassroomAudit;
use Acadly\Modules\Classroom\Contracts\ClassroomDocument;
use Acadly\Modules\Classroom\Notifications\CourseAnnounced;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A course page's resources, announcements and continuous-assessment share
 * (D-104). Only the lecturers named on the course change them.
 */
final class CourseActions
{
    public function __construct(
        private readonly Courses $courses,
        private readonly StoreDocument $store,
        private readonly Notifier $notifier,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * Adds a file or a link to the course's resources.
     *
     * @param  array{section: string, title: string, url: string|null, shows_from: string|null, pinned: bool}  $input
     */
    public function addResource(User $by, string $divisionId, string $offeringId, array $input, ?UploadedFile $file): string
    {
        $course = $this->courses->run($by, $divisionId, $offeringId);
        $errors = [];
        $section = trim($input['section']);
        $title = trim($input['title']);
        if ($section === '' || mb_strlen($section) > 80) {
            $errors['section'] = ['Give the week or topic, up to 80 characters.'];
        }
        if ($file === null && $title === '') {
            $errors['title'] = ['Give the resource a title.'];
        }
        $url = $input['url'] !== null ? trim($input['url']) : null;
        if ($file === null && ($url === null || preg_match('#^https?://[^\s]{3,490}$#i', $url) !== 1)) {
            $errors['url'] = ['Add a file, or give a web address starting with https://.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $id = Ids::next();

        DB::transaction(function () use ($by, $course, $input, $file, $id, $section, $title, $url): void {
            DB::table('classroom_resources')->insert(['id' => $id, 'division_id' => $course['division_id'], 'period_id' => $course['period']['id'],
                'offering_id' => $course['offering']['id'], 'section' => $section, 'title' => mb_substr($title !== '' ? $title : ($file !== null ? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) : ''), 0, 160),
                'kind' => $file !== null ? 'file' : 'link', 'url' => $file !== null ? null : $url, 'document_id' => null, 'shows_from' => $input['shows_from'],
                'pinned' => $input['pinned'], 'created_by' => $by->id, 'created_at' => $this->clock->now()]);
            if ($file !== null) {
                $document = $this->store->handle($by, ClassroomDocument::Resource, $id, $file, $title !== '' ? $title : null);
                DB::table('classroom_resources')->where('id', $id)->update(['document_id' => $document->id]);
            }
            $this->audit->record(ClassroomAudit::ResourceAdded, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $course['division_id']),
                meta: ['resource' => $id, 'offering' => $course['offering']['id']]);
        });

        return $id;
    }

    public function removeResource(User $by, string $divisionId, string $resourceId): void
    {
        $row = DB::table('classroom_resources')->where('id', $resourceId)->where('division_id', $divisionId)->whereNull('removed_at')->first() ?? throw new NotFoundHttpException;
        $course = $this->courses->run($by, $divisionId, Row::text($row, 'offering_id'));
        DB::transaction(function () use ($by, $resourceId, $course): void {
            DB::table('classroom_resources')->where('id', $resourceId)->update(['removed_at' => $this->clock->now(), 'removed_by' => $by->id]);
            $this->audit->record(ClassroomAudit::ResourceRemoved, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $course['division_id']), meta: ['resource' => $resourceId]);
        });
    }

    /** Posts an announcement to the course's students; by text message as well when asked. */
    public function announce(User $by, string $divisionId, string $offeringId, string $title, string $body, bool $sms): string
    {
        $course = $this->courses->run($by, $divisionId, $offeringId);
        $title = trim($title);
        $body = trim($body);
        $errors = [];
        if ($title === '' || mb_strlen($title) > 160) {
            $errors['title'] = ['Give a title of up to 160 characters.'];
        }
        if ($body === '' || mb_strlen($body) > 4000) {
            $errors['body'] = ['Write the announcement, up to 4,000 characters.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $id = Ids::next();
        DB::transaction(function () use ($by, $course, $id, $title, $body, $sms): void {
            DB::table('classroom_announcements')->insert(['id' => $id, 'division_id' => $course['division_id'], 'period_id' => $course['period']['id'],
                'offering_id' => $course['offering']['id'], 'title' => $title, 'body' => $body, 'sms' => $sms, 'by_user_id' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(ClassroomAudit::Announced, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $course['division_id']),
                meta: ['announcement' => $id, 'sms' => $sms]);
            if ($sms) {
                $this->notifier->send(new CourseAnnounced($id, $course['offering']['id'], $course['division_id'], $course['offering']['code'], $title), $by);
            }
        });

        return $id;
    }

    /** Sets how much of the course continuous assessment counts, in per cent. */
    public function setShare(User $by, string $divisionId, string $offeringId, int $share): void
    {
        $course = $this->courses->run($by, $divisionId, $offeringId);
        if ($share < 0 || $share > 100) {
            throw ValidationException::withMessages(['ca_share' => ['Give a share from 0 to 100.']]);
        }
        DB::transaction(function () use ($by, $course, $share): void {
            DB::table('classroom_shares')->upsert([['offering_id' => $course['offering']['id'], 'division_id' => $course['division_id'], 'ca_share' => $share,
                'updated_by' => $by->id, 'updated_at' => $this->clock->now()]], ['offering_id'], ['ca_share', 'updated_by', 'updated_at']);
            $this->audit->record(ClassroomAudit::ShareSet, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $course['division_id']),
                meta: ['offering' => $course['offering']['id'], 'share' => $share]);
        });
    }

    /** Marks an announcement read by a student: counted, never listed. */
    public function read(string $announcementId, string $userId): void
    {
        DB::table('classroom_announcement_reads')->insertOrIgnore(['announcement_id' => $announcementId, 'user_id' => $userId, 'read_at' => $this->clock->now()]);
    }

    public const int DEFAULT_SHARE = 40;

    public static function share(string $offeringId): int
    {
        $s = DB::table('classroom_shares')->where('offering_id', $offeringId)->value('ca_share');

        return is_numeric($s) ? (int) $s : self::DEFAULT_SHARE;
    }
}
