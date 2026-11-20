<?php

declare(strict_types=1);

namespace Acadly\Modules\News\Application;

use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Contracts\StudentPlaces;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reading news (D-101): the newest version of each post, for a student by
 * their place in the college, for staff in a division, and for those who
 * publish, with how many have seen each. Reads are counted, never listed.
 *
 * @phpstan-type Post array{id: string, title: string, body: string, audience: string, audience_id: string|null, year: int|null, audience_label: string, pinned: bool, pinned_until: string|null, publish_at: string, scheduled: bool, updated: bool, author: string, read: bool}
 */
final class NewsBoard
{
    public const array AUDIENCES = ['everyone', 'faculty', 'department', 'programme', 'year', 'staff'];

    public function __construct(private readonly Clock $clock) {}

    /**
     * The student portal's news: the posts meant for this student, pinned first. Null when the account is not a student's.
     *
     * @return list<Post>|null
     */
    public function forStudent(string $userId): ?array
    {
        $place = $this->places()?->of($userId);
        if ($place === null) {
            return null;
        }
        $q = $this->live()->where('p.division_id', $place['division_id'])->where('p.publish_at', '<=', $this->clock->now())
            ->where(fn (Builder $w): Builder => $w->where('p.audience', 'everyone')
                ->orWhere(fn (Builder $a): Builder => $a->where('p.audience', 'faculty')->where('p.audience_id', $place['faculty_id']))
                ->orWhere(fn (Builder $a): Builder => $a->where('p.audience', 'department')->where('p.audience_id', $place['department_id']))
                ->orWhere(fn (Builder $a): Builder => $a->where('p.audience', 'programme')->where('p.audience_id', $place['programme_id']))
                ->orWhere(fn (Builder $a): Builder => $a->where('p.audience', 'year')->where('p.year_of_study', $place['year_of_study'])));

        return $this->rows($q, $userId, $place['division_id']);
    }

    /**
     * The news staff of a division see: everyone's and staff-only news; for those who publish, all of it.
     *
     * @return list<Post>
     */
    public function forStaff(string $userId, string $divisionId, bool $publishes): array
    {
        $q = $this->live()->where('p.division_id', $divisionId);
        if (! $publishes) {
            $q->whereIn('p.audience', ['everyone', 'staff'])->where('p.publish_at', '<=', $this->clock->now());
        }

        return $this->rows($q, $userId, $divisionId);
    }

    /**
     * The post a student may open, by its first version's id, or null.
     *
     * @return Post|null
     */
    public function studentPost(string $userId, string $rootId): ?array
    {
        foreach ($this->forStudent($userId) ?? [] as $p) {
            if ($p['id'] === $rootId) {
                return $p;
            }
        }

        return null;
    }

    /** How many have seen each post. */
    public function seen(string $rootId): int
    {
        return DB::table('news_reads')->where('root_id', $rootId)->count();
    }

    /** The newest version of each post that is not withdrawn. */
    private function live(): Builder
    {
        return DB::table('news_posts as p')->leftJoin('users as u', 'u.id', '=', 'p.author_id')->whereNull('p.withdrawn_at')
            ->whereRaw('p.version = (select max(n.version) from news_posts n where n.root_id = p.root_id)');
    }

    /** @return list<Post> */
    private function rows(Builder $q, string $userId, string $divisionId): array
    {
        $today = $this->clock->today();
        $now = $this->clock->now()->toIso8601String();
        $read = array_flip(array_filter(DB::table('news_reads')->where('user_id', $userId)->pluck('root_id')->all(), 'is_string'));
        $labels = $this->labels($divisionId);
        $out = [];
        foreach ($q->orderByDesc('p.publish_at')->limit(100)->get(['p.root_id', 'p.version', 'p.title', 'p.body', 'p.audience', 'p.audience_id', 'p.year_of_study', 'p.pinned_until', 'p.publish_at', 'u.name']) as $r) {
            $pinned = Row::maybeText($r, 'pinned_until') !== null && Row::text($r, 'pinned_until') >= $today;
            $audience = Row::text($r, 'audience');
            $out[] = ['id' => Row::text($r, 'root_id'), 'title' => Row::text($r, 'title'), 'body' => Row::text($r, 'body'), 'audience' => $audience,
                'audience_id' => Row::maybeText($r, 'audience_id'), 'year' => ($r->year_of_study ?? null) !== null ? Row::int($r, 'year_of_study') : null,
                'pinned_until' => Row::maybeText($r, 'pinned_until'),
                'audience_label' => match ($audience) {
                    'everyone' => 'Everyone', 'staff' => 'Staff only',
                    'year' => 'Year '.(($r->year_of_study ?? null) !== null ? Row::int($r, 'year_of_study') : ''),
                    default => $labels[Row::maybeText($r, 'audience_id') ?? ''] ?? '',
                },
                'pinned' => $pinned, 'publish_at' => Row::text($r, 'publish_at'), 'scheduled' => Row::text($r, 'publish_at') > $now, 'updated' => Row::int($r, 'version') > 1,
                'author' => Row::maybeText($r, 'name') ?? '', 'read' => isset($read[Row::text($r, 'root_id')])];
        }
        usort($out, fn (array $a, array $b): int => [$b['pinned'], $b['publish_at']] <=> [$a['pinned'], $a['publish_at']]);

        return $out;
    }

    /** @return array<string, string> faculty, department and programme names by id */
    private function labels(string $divisionId): array
    {
        $s = $this->places()?->structure($divisionId);
        if ($s === null) {
            return [];
        }

        return array_column([...$s['faculties'], ...$s['departments'], ...$s['programmes']], 'name', 'id');
    }

    private function places(): ?StudentPlaces
    {
        return app()->bound(StudentPlaces::class) ? app(StudentPlaces::class) : null;
    }
}
