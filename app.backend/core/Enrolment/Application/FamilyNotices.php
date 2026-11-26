<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Messages;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Phone;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Notices to families (D-115): who a notice reaches, the notices staff see
 * in the place they work in, and the notices a parent reads for their
 * children. A family is a main contact's phone; a parent reads a notice once,
 * however many of their children it is for.
 *
 * @phpstan-type Audience array{audience: string, division_id: string|null, level_code: string|null, class_id: string|null}
 * @phpstan-type FamilyNotice array{id: string, title: string, body: string, for: string, publish_at: string, pinned: bool, new: bool, from: string}
 */
final class FamilyNotices
{
    public const array AUDIENCES = ['school', 'division', 'level', 'class'];

    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly Families $families,
        private readonly Messages $messages,
        private readonly SchoolDirectory $school,
        private readonly Clock $clock,
    ) {}

    /** Where a notice is published, for the permission check and the place walls. */
    public static function scopeOf(string $audience, ?string $divisionId, ?string $classId): ScopeRef
    {
        return match ($audience) {
            'class' => ScopeRef::of(ScopeType::ClassGroup, (string) $classId),
            'division', 'level' => ScopeRef::of(ScopeType::Division, (string) $divisionId),
            default => ScopeRef::institution(),
        };
    }

    /**
     * The learners placed now that a notice is for.
     *
     * @param  Audience  $a
     * @return list<string>
     */
    public function learners(array $a): array
    {
        $q = DB::table('placements')->where(fn (Builder $w): Builder => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $this->clock->today()))
            ->where('starts_on', '<=', $this->clock->today());

        match ($a['audience']) {
            'class' => $q->where('class_id', $a['class_id']),
            'level' => $q->where('division_id', $a['division_id'])->where('level_code', $a['level_code']),
            'division' => $q->where('division_id', $a['division_id']),
            default => null,
        };

        return array_values(array_unique(array_filter($q->pluck('learner_id')->all(), 'is_string')));
    }

    /**
     * Each family a notice reaches: the main contact of each learner, once per phone.
     *
     * @param  list<string>  $learnerIds
     * @return array<string, array{name: string, phone: string, email: string|null, by: string}> phone => contact
     */
    public function mainContacts(array $learnerIds): array
    {
        $out = [];

        foreach (array_chunk($learnerIds, 1000) as $chunk) {
            foreach (DB::table('learner_contacts')->whereIn('learner_id', $chunk)->where('is_main', true)->whereNull('removed_at')
                ->get(['name', 'phone', 'email', 'notices_by']) as $c) {
                $phone = Phone::international(Row::text($c, 'phone'));

                if ($phone !== null) {
                    $out[$phone] ??= ['name' => Row::text($c, 'name'), 'phone' => $phone, 'email' => Row::maybeText($c, 'email'), 'by' => Row::text($c, 'notices_by')];
                }
            }
        }

        return $out;
    }

    /**
     * How many families a notice reaches, how many use the portal, and how many texts it would send.
     *
     * @param  Audience  $a
     * @return array{learners: int, families: int, in_portal: int, texts: int}
     */
    public function reach(array $a): array
    {
        $learners = $this->learners($a);
        $contacts = $this->mainContacts($learners);
        $portal = $learners === [] ? 0 : DB::table('learner_guardian_relationships')->whereIn('learner_id', $learners)->where('state', 'active')
            ->whereNotNull('held_by')->distinct()->count('held_by');

        return ['learners' => count($learners), 'families' => count($contacts), 'in_portal' => $portal,
            'texts' => count(array_filter($contacts, fn (array $c): bool => $c['by'] !== 'email'))];
    }

    /**
     * The notices staff see in the place they work in, newest first, with how many families read each.
     *
     * @return list<array{id: string, title: string, for: string, publish_at: string, scheduled: bool, author: string, read: int, families: int, texts: array{all: int, delivered: int, not_delivered: int}|null, withdrawn: array{reason: string, at: string}|null}>
     */
    public function forStaff(User $viewer): array
    {
        $out = [];

        foreach (DB::table('family_notices as n')->leftJoin('family_notice_withdrawals as w', 'w.notice_id', '=', 'n.id')->leftJoin('users as u', 'u.id', '=', 'n.author_id')
            ->orderByDesc('n.publish_at')->limit(200)->get(['n.*', 'w.reason as withdrawn_reason', 'w.created_at as withdrawn_at', 'u.name as author']) as $n) {
            $a = self::audienceOf($n);

            if (! $this->access->allows($viewer, EnrolmentPermission::Notify, self::scopeOf($a['audience'], $a['division_id'], $a['class_id']))) {
                continue;
            }

            $id = Row::text($n, 'id');
            $out[] = ['id' => $id, 'title' => Row::text($n, 'title'), 'for' => $this->label($a), 'publish_at' => Row::text($n, 'publish_at'),
                'scheduled' => Row::text($n, 'publish_at') > $this->clock->now()->toIso8601String(), 'author' => Row::maybeText($n, 'author') ?? '',
                'read' => DB::table('family_notice_reads')->where('notice_id', $id)->count(), 'families' => count($this->mainContacts($this->learners($a))),
                'texts' => Row::maybeText($n, 'sms_text') === null ? null : $this->messages->tally('notice', $id),
                'withdrawn' => Row::maybeText($n, 'withdrawn_reason') === null ? null : ['reason' => Row::text($n, 'withdrawn_reason'), 'at' => Row::text($n, 'withdrawn_at')]];
        }

        return $out;
    }

    /**
     * Who the person may write to in the place they work in: the whole school, and each division with its levels and classes.
     *
     * @return array{school: bool, divisions: list<array{id: string, name: string, whole: bool, levels: list<array{code: string, label: string}>, classes: list<array{id: string, name: string}>}>}
     */
    public function choices(User $viewer): array
    {
        $year = $this->calendar->currentYear();
        $classes = $year === null ? [] : $this->calendar->classesInYear($year['id']);
        $out = [];

        foreach ($this->school->divisionViews() as $d) {
            $whole = $this->access->allows($viewer, EnrolmentPermission::Notify, ScopeRef::of(ScopeType::Division, $d->id));
            $mine = array_values(array_map(fn (array $c): array => ['id' => $c['id'], 'name' => $c['name']], array_filter($classes, fn (array $c): bool => $c['division_id'] === $d->id
                && ($whole || $this->access->allows($viewer, EnrolmentPermission::Notify, ScopeRef::of(ScopeType::ClassGroup, $c['id']))))));

            if ($whole || $mine !== []) {
                $out[] = ['id' => $d->id, 'name' => $d->name, 'whole' => $whole, 'levels' => $whole ? $d->profile->levels : [], 'classes' => $mine];
            }
        }

        return ['school' => $this->access->allows($viewer, EnrolmentPermission::Notify, ScopeRef::institution()), 'divisions' => $out];
    }

    /**
     * The notices a parent reads: those for any of their children now, published and not withdrawn, pinned first.
     *
     * @return list<FamilyNotice>
     */
    public function forParent(User $parent): array
    {
        $places = [];

        foreach ($this->families->children($parent) as $child) {
            $place = DB::table('placements')->where('learner_id', $child['id'])->whereNull('ends_on')->first(['division_id', 'level_code', 'class_id']);

            if ($place !== null) {
                $places[] = $place;
            }
        }

        if ($places === []) {
            return [];
        }

        $now = $this->clock->now();
        $q = DB::table('family_notices as n')->leftJoin('family_notice_withdrawals as w', 'w.notice_id', '=', 'n.id')->leftJoin('users as u', 'u.id', '=', 'n.author_id')
            ->leftJoin('family_notice_reads as r', fn (JoinClause $j): JoinClause => $j->on('r.notice_id', '=', 'n.id')->where('r.user_id', '=', $parent->id))
            ->whereNull('w.notice_id')->where('n.publish_at', '<=', $now)
            ->where(function (Builder $w) use ($places): void {
                $w->where('n.audience', 'school');

                foreach ($places as $p) {
                    $w->orWhere(fn (Builder $x): Builder => $x->where('n.audience', 'division')->where('n.division_id', Row::text($p, 'division_id')))
                        ->orWhere(fn (Builder $x): Builder => $x->where('n.audience', 'level')->where('n.division_id', Row::text($p, 'division_id'))->where('n.level_code', Row::text($p, 'level_code')))
                        ->orWhere(fn (Builder $x): Builder => $x->where('n.audience', 'class')->where('n.class_id', Row::text($p, 'class_id')));
                }
            })
            ->orderByDesc('n.publish_at')->limit(50);
        $today = $this->clock->today();
        $out = [];

        foreach ($q->get(['n.*', 'u.name as author', 'r.read_at']) as $n) {
            $pinned = Row::maybeText($n, 'pinned_until') !== null && Row::text($n, 'pinned_until') >= $today;
            $out[] = ['id' => Row::text($n, 'id'), 'title' => Row::text($n, 'title'), 'body' => Row::text($n, 'body'), 'for' => $this->label(self::audienceOf($n)),
                'publish_at' => Row::text($n, 'publish_at'), 'pinned' => $pinned, 'new' => Row::maybeText($n, 'read_at') === null, 'from' => Row::maybeText($n, 'author') ?? ''];
        }

        usort($out, fn (array $a, array $b): int => [$b['pinned'], $b['publish_at']] <=> [$a['pinned'], $a['publish_at']]);

        return $out;
    }

    /**
     * One notice a parent may open; opening it counts it as read.
     *
     * @return FamilyNotice
     */
    public function open(User $parent, string $id): array
    {
        foreach ($this->forParent($parent) as $n) {
            if ($n['id'] === $id) {
                DB::table('family_notice_reads')->insertOrIgnore(['notice_id' => $id, 'user_id' => $parent->id, 'read_at' => $this->clock->now()]);

                return [...$n, 'new' => false];
            }
        }

        throw new NotFoundHttpException;
    }

    /** @return Audience */
    public static function audienceOf(object $row): array
    {
        return ['audience' => Row::text($row, 'audience'), 'division_id' => Row::maybeText($row, 'division_id'), 'level_code' => Row::maybeText($row, 'level_code'),
            'class_id' => Row::maybeText($row, 'class_id')];
    }

    /**
     * "Whole school", "Secondary", "Form 2 · Secondary", "Form 2 West".
     *
     * @param  Audience  $a
     */
    public function label(array $a): string
    {
        $division = $a['division_id'] !== null && Ids::isValid($a['division_id']) ? $this->school->division($a['division_id']) : null;

        return match ($a['audience']) {
            'class' => $this->calendar->classNames([(string) $a['class_id']])[(string) $a['class_id']]['name'] ?? '',
            'level' => ($division?->profile->levelLabel((string) $a['level_code']) ?? (string) $a['level_code']).' · '.($division->name ?? ''),
            'division' => $division->name ?? '',
            default => 'Whole school',
        };
    }
}
