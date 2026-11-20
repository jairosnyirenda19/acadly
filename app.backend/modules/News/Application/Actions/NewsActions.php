<?php

declare(strict_types=1);

namespace Acadly\Modules\News\Application\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\News\Application\NewsBoard;
use Acadly\Modules\News\Contracts\NewsAudit;
use Acadly\Modules\News\Contracts\NewsPermission;
use Acadly\Modules\Programmes\Contracts\StudentPlaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Publishing news (D-101). A change after publishing is a new version; the
 * first stays. Withdrawing hides the news, with a reason, and keeps it.
 *
 * @phpstan-type Input array{title: string, body: string, audience: string, audience_id: string|null, year: int|null, pinned_until: string|null, publish_at: string|null}
 */
final class NewsActions
{
    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  Input  $input
     * @return string the post's id
     */
    public function publish(User $by, string $divisionId, array $input): string
    {
        $at = ScopeRef::of(ScopeType::Division, $divisionId);
        $this->access->check($by, NewsPermission::Publish, $at);
        $row = $this->row($divisionId, $input);
        $id = Ids::next();

        DB::transaction(function () use ($by, $at, $row, $id): void {
            DB::table('news_posts')->insert([...$row, 'id' => $id, 'root_id' => $id, 'version' => 1, 'author_id' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(NewsAudit::Published, actor: $this->audit->actorFor($by), at: $at, meta: ['post' => $id, 'audience' => $row['audience']]);
        });

        return $id;
    }

    /** @param  Input  $input */
    public function edit(User $by, string $rootId, array $input): void
    {
        $last = $this->latest($rootId);
        $divisionId = Row::text($last, 'division_id');
        $at = ScopeRef::of(ScopeType::Division, $divisionId);
        $this->access->check($by, NewsPermission::Publish, $at);
        $row = $this->row($divisionId, $input);

        DB::transaction(function () use ($by, $at, $row, $rootId, $last): void {
            DB::table('news_posts')->insert([...$row, 'id' => Ids::next(), 'root_id' => $rootId, 'version' => Row::int($last, 'version') + 1, 'author_id' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(NewsAudit::Edited, actor: $this->audit->actorFor($by), at: $at, meta: ['post' => $rootId]);
        });
    }

    public function withdraw(User $by, string $rootId, string $reason): void
    {
        $last = $this->latest($rootId);
        $at = ScopeRef::of(ScopeType::Division, Row::text($last, 'division_id'));
        $this->access->check($by, NewsPermission::Publish, $at);
        $reason = trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : throw ValidationException::withMessages(['reason' => ['Give the reason.']]);

        DB::transaction(function () use ($by, $at, $rootId, $reason): void {
            DB::table('news_posts')->where('root_id', $rootId)->update(['withdrawn_at' => $this->clock->now(), 'withdrawn_by' => $by->id, 'withdrawn_reason' => $reason]);
            $this->audit->record(NewsAudit::Withdrawn, actor: $this->audit->actorFor($by), at: $at, reason: $reason, meta: ['post' => $rootId]);
        });
    }

    /** Marks a post read by a person: counted, never listed. */
    public function read(string $rootId, string $userId): void
    {
        DB::table('news_reads')->insertOrIgnore(['root_id' => $rootId, 'user_id' => $userId, 'read_at' => $this->clock->now()]);
    }

    private function latest(string $rootId): object
    {
        return DB::table('news_posts')->where('root_id', $rootId)->whereNull('withdrawn_at')->orderByDesc('version')->first() ?? throw new NotFoundHttpException;
    }

    /**
     * @param  Input  $input
     * @return array{division_id: string, title: string, body: string, audience: string, audience_id: string|null, year_of_study: int|null, pinned_until: string|null, publish_at: CarbonImmutable}
     */
    private function row(string $divisionId, array $input): array
    {
        $errors = [];
        $title = trim($input['title']);
        $body = trim($input['body']);
        if ($title === '' || mb_strlen($title) > 160) {
            $errors['title'] = ['Give a title of up to 160 characters.'];
        }
        if ($body === '' || mb_strlen($body) > 10000) {
            $errors['body'] = ['Write the news, up to 10,000 characters.'];
        }
        $audience = $input['audience'];
        if (! in_array($audience, NewsBoard::AUDIENCES, true)) {
            $errors['audience'] = ['Choose who sees it.'];
        }
        $id = in_array($audience, ['faculty', 'department', 'programme'], true) ? $input['audience_id'] : null;
        if ($id !== null || in_array($audience, ['faculty', 'department', 'programme'], true)) {
            $s = app()->bound(StudentPlaces::class) ? app(StudentPlaces::class)->structure($divisionId) : ['faculties' => [], 'departments' => [], 'programmes' => [], 'years' => []];
            $known = array_column(match ($audience) {
                'faculty' => $s['faculties'], 'department' => $s['departments'], default => $s['programmes']
            }, 'id');
            if ($id === null || ! in_array($id, $known, true)) {
                $errors['audience_id'] = ['Choose the '.$audience.'.'];
            }
        }
        $year = $audience === 'year' ? $input['year'] : null;
        if ($audience === 'year' && ($year === null || $year < 1 || $year > 10)) {
            $errors['year'] = ['Choose the year of study.'];
        }
        $today = $this->clock->today();
        if ($input['pinned_until'] !== null && $input['pinned_until'] < $today) {
            $errors['pinned_until'] = ['Pin it until today or a later day.'];
        }
        $publishAt = $input['publish_at'] !== null ? CarbonImmutable::parse($input['publish_at']) : $this->clock->now();
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['division_id' => $divisionId, 'title' => $title, 'body' => $body, 'audience' => $audience, 'audience_id' => $id, 'year_of_study' => $year,
            'pinned_until' => $input['pinned_until'], 'publish_at' => $publishAt->max($this->clock->now())];
    }
}
