<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Documents\DocumentReader;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Attendance\Contracts\MissedLessons;
use Acadly\Modules\Conduct\Application\Actions\CaseActions;
use Acadly\Modules\Conduct\Contracts\ConductPermission;
use Acadly\Modules\Conduct\Domain\ConductCase;
use Acadly\Modules\Conduct\Domain\Sanction;
use Acadly\Modules\Conduct\Journeys\SeriousIncident\State;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the conduct screens read (D-092): a learner's conduct, a serious
 * incident, the school's overview, and the learners to watch. Withdrawn
 * entries are shown as withdrawn and counted nowhere.
 */
final class ConductViews
{
    private const array OPEN = [State::Reported->value, State::LookedInto->value, State::Decided->value, State::FamilyMet->value];

    public function __construct(
        private readonly ConductReach $reach,
        private readonly Access $access,
        private readonly EnrolmentDirectory $directory,
        private readonly AcademicCalendar $calendar,
        private readonly DocumentReader $documents,
        private readonly CaseActions $cases,
        private readonly Clock $clock,
        private readonly MissedLessons $missed,
    ) {}

    /** @return array<string, mixed> */
    public function learner(User $viewer, string $learnerId): array
    {
        $this->reach->learner($viewer, $learnerId, ConductPermission::View);
        $entries = $this->entries([$learnerId]);
        $division = $this->directory->placeOf($learnerId)['division_id'] ?? null;
        $terms = $division === null ? [] : $this->terms($division);
        $live = array_values(array_filter($entries, fn (array $e): bool => $e['withdrawn'] === null));
        $byTerm = array_map(fn (array $t): array => [
            'name' => $t['name'],
            'incidents' => count(array_filter($live, fn (array $e): bool => ! $e['commendation'] && $e['happened_on'] >= $t['starts_on'] && $e['happened_on'] <= $t['ends_on'])),
            'commendations' => count(array_filter($live, fn (array $e): bool => $e['commendation'] && $e['happened_on'] >= $t['starts_on'] && $e['happened_on'] <= $t['ends_on'])),
            'current' => $t['current'],
        ], $terms);

        // Other learners in an entry are named only to someone who may see them.
        foreach ($entries as &$e) {
            $e['others'] = array_values(array_filter($e['others'], fn (array $o): bool => $o['id'] !== $learnerId && $this->reach->may($viewer, ConductPermission::View, $o['id'])));
        }

        unset($e);

        return [
            'entries' => $entries,
            'terms' => $byTerm,
            'watch' => $this->watch([$learnerId])[$learnerId] ?? [],
            'can' => ['record' => $this->reach->may($viewer, ConductPermission::Record, $learnerId), 'follow_up' => $this->reach->may($viewer, ConductPermission::FollowUp, $learnerId)],
        ];
    }

    /** @return array<string, mixed> */
    public function incident(User $viewer, string $caseId): array
    {
        $case = $this->cases->find($viewer, $caseId);
        $entry = $this->entries(null, $case->entry_id)[0] ?? null;
        $followUp = $this->access->allows($viewer, ConductPermission::FollowUp, $case->journeyScope());
        $outcomes = CaseActions::latestOutcomes($case->id);
        $ends = Suspensions::endsOf(array_values(array_map(fn (object $o): string => Row::text($o, 'id'), $outcomes)));
        $decided = in_array($case->state, Suspensions::DECIDED, true);
        $learners = [];

        foreach (DB::table('conduct_entry_learners')->where('entry_id', $case->entry_id)->get(['learner_id', 'class_id']) as $l) {
            $id = Row::text($l, 'learner_id');
            $o = $outcomes[$id] ?? null;
            $learners[] = ['id' => $id, 'name' => $this->directory->names([$id])[$id] ?? '', 'class' => $this->className(Row::text($l, 'class_id')),
                'outcome' => $o === null ? null : ['outcome' => Row::text($o, 'outcome'), 'text' => CaseActions::describe($o), 'reason' => Row::text($o, 'reason'),
                    'ended_after' => $ends[Row::text($o, 'id')] ?? null,
                    'can_end' => $followUp && $decided && Row::text($o, 'outcome') === Sanction::Suspension->value && ! isset($ends[Row::text($o, 'id')])]];
        }

        $notes = [];

        foreach (DB::table('conduct_case_notes as n')->leftJoin('users as u', 'u.id', '=', 'n.written_by')->where('n.case_id', $case->id)->orderBy('n.created_at')
            ->get(['n.id', 'n.text', 'n.created_at', 'u.name']) as $n) {
            $notes[] = ['id' => Row::text($n, 'id'), 'text' => Row::text($n, 'text'), 'at' => Row::text($n, 'created_at'), 'by' => Row::maybeText($n, 'name')];
        }

        $docs = [];

        foreach ($followUp ? $this->documents->ownedBy($viewer, 'conduct_case', $case->id) : [] as $d) {
            $docs[] = ['id' => $d->id, 'title' => $d->title];
        }

        $lead = $case->lead_by === null ? null : DB::table('users')->where('id', $case->lead_by)->value('name');
        $reporter = $entry !== null && $entry['recorded_by_id'] === $viewer->id;

        return [
            'id' => $case->id,
            'number' => $case->number,
            'state' => $case->state,
            'state_label' => State::from($case->state)->label(),
            'entry' => $entry,
            'lead' => is_string($lead) ? $lead : null,
            'learners' => $learners,
            'notes' => $notes,
            'documents' => $docs,
            'can' => [
                'follow_up' => $followUp,
                'take_up' => $followUp && $case->state === State::Reported->value,
                'decide' => $followUp && ! $reporter && $case->state === State::LookedInto->value,
                'meet' => $followUp && $case->state === State::Decided->value,
                'close' => $followUp && $case->state === State::FamilyMet->value,
                'withdraw' => $followUp && in_array($case->state, [State::Reported->value, State::LookedInto->value], true),
                'reporter' => $reporter,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function overview(User $viewer): array
    {
        if (! $this->access->allows($viewer, ConductPermission::FollowUp)) {
            $this->access->check($viewer, ConductPermission::View);
        }

        [$now, $before] = $this->schoolTerms();
        $openCases = [];
        // Only what concerns learners in classes the viewer may see, in the place worked in (D-099).
        $classes = $this->seenClasses($viewer);
        $seenEntries = array_values(DB::table('conduct_entry_learners')->whereIn('class_id', $classes ?: ['00000000-0000-0000-0000-000000000000'])->distinct()->pluck('entry_id')->map(fn ($v): string => is_string($v) ? $v : '')->all());

        foreach (ConductCase::query()->whereIn('state', self::OPEN)->whereIn('entry_id', $seenEntries ?: ['00000000-0000-0000-0000-000000000000'])->orderBy('created_at')->get() as $case) {
            $entry = $this->entries(null, $case->entry_id)[0] ?? null;
            $lead = $case->lead_by === null ? null : DB::table('users')->where('id', $case->lead_by)->value('name');
            $openCases[] = ['id' => $case->id, 'number' => $case->number, 'state' => $case->state, 'state_label' => State::from($case->state)->label(),
                'kind' => $entry['kind'] ?? '', 'happened_on' => $entry['happened_on'] ?? '', 'learners' => implode(', ', array_column(array_filter($entry['others'] ?? [], fn (array $o): bool => $this->reach->may($viewer, ConductPermission::View, $o['id'])), 'name')),
                'lead' => is_string($lead) ? $lead : null, 'days' => (int) CarbonImmutable::parse($case->created_at->toDateString())->diffInDays(CarbonImmutable::parse($this->clock->today()))];
        }

        $since = CarbonImmutable::parse($this->clock->today())->subDays(120)->toDateString();
        $recent = array_values(array_filter(DB::table('conduct_entry_learners as l')->join('conduct_entries as e', 'e.id', '=', 'l.entry_id')
            ->whereIn('l.class_id', $classes ?: ['00000000-0000-0000-0000-000000000000'])->where('e.happened_on', '>=', $since)->distinct()->pluck('l.learner_id')->all(), 'is_string'));
        $watch = [];
        $names = $this->directory->names($recent);

        foreach ($this->watch($recent) as $learner => $reasons) {
            if ($reasons !== [] && $this->reach->may($viewer, ConductPermission::View, $learner)) {
                $watch[] = ['id' => $learner, 'name' => $names[$learner] ?? '', 'class' => $this->className((string) $this->reach->classOf($learner)), 'reasons' => $reasons];
            }
        }

        $incidents = ($now === null ? [] : $this->countsByKind($now, false, $seenEntries));
        $commendations = ($now === null ? [] : $this->countsByKind($now, true, $seenEntries));
        $kinds = [];

        foreach ([[...$commendations, ...$incidents], [...($before === null ? [] : $this->countsByKind($before, true, $seenEntries)), ...($before === null ? [] : $this->countsByKind($before, false, $seenEntries))]] as $i => $rows) {
            foreach ($rows as $name => $row) {
                $kinds[$name] ??= ['name' => $name, 'commendation' => $row['commendation'], 'now' => 0, 'before' => 0];
                $kinds[$name][$i === 0 ? 'now' : 'before'] = $row['count'];
            }
        }

        $levels = ['minor' => 0, 'moderate' => 0, 'serious' => 0];

        foreach ($now === null ? [] : DB::table('conduct_entries as e')->leftJoin('conduct_withdrawals as w', 'w.entry_id', '=', 'e.id')->whereNull('w.entry_id')
            ->where('e.commendation', false)->whereBetween('e.happened_on', $now)->whereIn('e.id', $seenEntries ?: ['00000000-0000-0000-0000-000000000000'])->get(['e.level']) as $r) {
            $levels[Row::text($r, 'level')] = ($levels[Row::text($r, 'level')] ?? 0) + 1;
        }

        return [
            'commendations' => array_sum(array_column($commendations, 'count')),
            'commendations_before' => array_sum(array_column(($before === null ? [] : $this->countsByKind($before, true, $seenEntries)), 'count')),
            'incidents' => array_sum(array_column($incidents, 'count')),
            'levels' => $levels,
            'open_cases' => $openCases,
            'watch' => $watch,
            'kinds' => array_values($kinds),
            'rules' => self::rules(),
        ];
    }

    /**
     * Why each learner is one to watch, by the school's rules. An empty list: nothing to watch.
     *
     * @param  list<string>  $learnerIds
     * @return array<string, list<string>>
     */
    public function watch(array $learnerIds): array
    {
        $rules = self::rules();
        $since = CarbonImmutable::parse($this->clock->today())->subDays($rules['days'])->toDateString();
        $out = array_fill_keys($learnerIds, []);
        $counts = [];

        foreach ($learnerIds === [] ? [] : DB::table('conduct_entry_learners as l')->join('conduct_entries as e', 'e.id', '=', 'l.entry_id')
            ->leftJoin('conduct_withdrawals as w', 'w.entry_id', '=', 'e.id')->whereNull('w.entry_id')->where('e.commendation', false)
            ->whereIn('l.learner_id', $learnerIds)->where('e.happened_on', '>=', $since)->get(['l.learner_id']) as $r) {
            $counts[Row::text($r, 'learner_id')] = ($counts[Row::text($r, 'learner_id')] ?? 0) + 1;
        }

        foreach ($counts as $learner => $n) {
            if ($n >= $rules['incidents']) {
                $out[$learner][] = "{$n} incidents in {$rules['days']} days.";
            }
        }

        // Incidents and missed lessons in the same days (D-111): lessons missed with no accepted reason.
        if ($rules['missed_incidents'] > 0 && $learnerIds !== []) {
            $today = $this->clock->today();
            $from = CarbonImmutable::parse($today)->subDays($rules['missed_days'] - 1)->toDateString();
            $recent = [];

            foreach (DB::table('conduct_entry_learners as l')->join('conduct_entries as e', 'e.id', '=', 'l.entry_id')
                ->leftJoin('conduct_withdrawals as w', 'w.entry_id', '=', 'e.id')->whereNull('w.entry_id')->where('e.commendation', false)
                ->whereIn('l.learner_id', $learnerIds)->whereBetween('e.happened_on', [$from, $today])->get(['l.learner_id']) as $r) {
                $recent[Row::text($r, 'learner_id')] = ($recent[Row::text($r, 'learner_id')] ?? 0) + 1;
            }

            $enough = array_keys(array_filter($recent, fn (int $n): bool => $n >= $rules['missed_incidents']));
            $missed = $this->missed->byDay(array_map(strval(...), $enough), $from, $today);

            foreach ($enough as $learner) {
                $lessons = array_sum($missed[$learner] ?? []);

                if ($lessons >= $rules['missed_lessons']) {
                    $out[$learner][] = "{$recent[$learner]} incidents and {$lessons} unexplained missed lessons in {$rules['missed_days']} days.";
                }
            }
        }

        if ($rules['open_serious'] && $learnerIds !== []) {
            foreach (DB::table('conduct_cases as c')->join('conduct_entry_learners as l', 'l.entry_id', '=', 'c.entry_id')->whereIn('c.state', self::OPEN)
                ->whereIn('l.learner_id', $learnerIds)->distinct()->pluck('l.learner_id') as $learner) {
                if (is_string($learner)) {
                    $out[$learner][] = 'An open serious incident.';
                }
            }
        }

        return $out;
    }

    /** @return array{incidents: int, days: int, open_serious: bool, missed_incidents: int, missed_lessons: int, missed_days: int, portal: bool} */
    public static function rules(): array
    {
        $row = DB::table('conduct_watch')->where('id', 1)->first();

        return $row === null ? ['incidents' => 3, 'days' => 30, 'open_serious' => true, 'missed_incidents' => 2, 'missed_lessons' => 4, 'missed_days' => 14, 'portal' => true]
            : ['incidents' => Row::int($row, 'incidents'), 'days' => Row::int($row, 'days'), 'open_serious' => (bool) $row->open_serious,
                'missed_incidents' => Row::int($row, 'missed_incidents'), 'missed_lessons' => Row::int($row, 'missed_lessons'), 'missed_days' => Row::int($row, 'missed_days'),
                'portal' => (bool) $row->portal];
    }

    /**
     * Entries of some learners, or one entry, newest first.
     *
     * @param  list<string>|null  $learnerIds
     * @return list<array{id: string, kind: string, commendation: bool, level: string|null, happened_on: string, place: string|null, what: string, action: string|null, told_family: bool, recorded_by: string|null, recorded_by_id: string, withdrawn: array{reason: string, by: string|null}|null, case: array{id: string, number: string, state: string, state_label: string}|null, others: list<array{id: string, name: string}>}>
     */
    private function entries(?array $learnerIds, ?string $entryId = null): array
    {
        $q = DB::table('conduct_entries as e')->leftJoin('users as u', 'u.id', '=', 'e.recorded_by')->leftJoin('conduct_withdrawals as w', 'w.entry_id', '=', 'e.id')
            ->leftJoin('users as wu', 'wu.id', '=', 'w.withdrawn_by')->leftJoin('conduct_cases as c', 'c.entry_id', '=', 'e.id')
            ->orderByDesc('e.happened_on')->orderByDesc('e.created_at');

        if ($entryId !== null) {
            $q->where('e.id', $entryId);
        } else {
            $q->whereIn('e.id', DB::table('conduct_entry_learners')->whereIn('learner_id', $learnerIds ?? [])->select('entry_id'));
        }

        $rows = $q->get(['e.id', 'e.kind_name', 'e.commendation', 'e.level', 'e.happened_on', 'e.place', 'e.what', 'e.action', 'e.told_family', 'e.recorded_by', 'u.name as by',
            'w.reason as withdrawn_reason', 'wu.name as withdrawn_by', 'w.entry_id as withdrawn', 'c.id as case_id', 'c.number as case_number', 'c.state as case_state']);
        $ids = array_map(fn (object $r): string => Row::text($r, 'id'), $rows->all());
        $members = [];

        foreach ($ids === [] ? [] : DB::table('conduct_entry_learners')->whereIn('entry_id', $ids)->get(['entry_id', 'learner_id']) as $m) {
            $members[Row::text($m, 'entry_id')][] = Row::text($m, 'learner_id');
        }

        $names = $this->directory->names(array_values(array_unique(array_merge([], ...array_values($members)))));
        $out = [];

        foreach ($rows as $r) {
            $id = Row::text($r, 'id');
            $state = Row::maybeText($r, 'case_state');
            $out[] = [
                'id' => $id,
                'kind' => Row::text($r, 'kind_name'),
                'commendation' => (bool) $r->commendation,
                'level' => Row::maybeText($r, 'level'),
                'happened_on' => Row::text($r, 'happened_on'),
                'place' => Row::maybeText($r, 'place'),
                'what' => Row::text($r, 'what'),
                'action' => Row::maybeText($r, 'action'),
                'told_family' => (bool) $r->told_family,
                'recorded_by' => Row::maybeText($r, 'by'),
                'recorded_by_id' => Row::text($r, 'recorded_by'),
                'withdrawn' => Row::maybeText($r, 'withdrawn') === null ? null : ['reason' => Row::text($r, 'withdrawn_reason'), 'by' => Row::maybeText($r, 'withdrawn_by')],
                'case' => $state === null ? null : ['id' => Row::text($r, 'case_id'), 'number' => Row::text($r, 'case_number'), 'state' => $state, 'state_label' => State::from($state)->label()],
                'others' => array_map(fn (string $l): array => ['id' => $l, 'name' => $names[$l] ?? ''], $members[$id] ?? []),
            ];
        }

        return $out;
    }

    /**
     * This term and the last for a division, then the two before them.
     *
     * @return list<array{name: string, starts_on: string, ends_on: string, current: bool}>
     */
    private function terms(string $divisionId): array
    {
        $today = $this->clock->today();
        $year = $this->calendar->currentYear();
        $list = $year['terms'][$divisionId] ?? [];

        if ($year !== null) {
            $before = $this->calendar->currentYear(CarbonImmutable::parse($year['starts_on'])->subDay()->toDateString());

            if ($before !== null && $before['id'] !== $year['id']) {
                $list = [...array_map(fn (array $t): array => [...$t, 'name' => $t['name'].' · '.$before['name']], $before['terms'][$divisionId] ?? []), ...array_map(fn (array $t): array => [...$t, 'name' => $t['name'].' · '.$year['name']], $list)];
            }
        }

        $list = array_values(array_filter($list, fn (array $t): bool => $t['starts_on'] <= $today));

        return array_map(fn (array $t): array => ['name' => $t['name'], 'starts_on' => $t['starts_on'], 'ends_on' => $t['ends_on'], 'current' => $t['ends_on'] >= $today],
            array_slice($list, -4));
    }

    /** @return array{0: array{0: string, 1: string}|null, 1: array{0: string, 1: string}|null} the dates of this term and the last, across the school */
    private function schoolTerms(): array
    {
        $today = $this->clock->today();
        $year = $this->calendar->currentYear();
        $all = [];

        foreach ($year['terms'] ?? [] as $terms) {
            foreach ($terms as $t) {
                $all[] = $t;
            }
        }

        $now = array_values(array_filter($all, fn (array $t): bool => $t['starts_on'] <= $today && $t['ends_on'] >= $today));
        $past = array_values(array_filter($all, fn (array $t): bool => $t['ends_on'] < $today));
        usort($past, fn (array $a, array $b): int => $b['ends_on'] <=> $a['ends_on']);

        $last = $past === [] ? [] : array_values(array_filter($past, fn (array $t): bool => $t['ends_on'] === $past[0]['ends_on']));

        return [self::span($now), self::span($last)];
    }

    /**
     * The first and last day of some terms.
     *
     * @param  list<array{starts_on: string, ends_on: string}>  $terms
     * @return array{0: string, 1: string}|null
     */
    private static function span(array $terms): ?array
    {
        if ($terms === []) {
            return null;
        }

        $starts = array_column($terms, 'starts_on');
        $ends = array_column($terms, 'ends_on');
        sort($starts);
        rsort($ends);

        return [$starts[0], $ends[0]];
    }

    /**
     * @param  array{0: string, 1: string}  $range
     * @param  list<string>  $entries  the entries the viewer may count
     * @return array<string, array{count: int, commendation: bool}>
     */
    private function countsByKind(array $range, bool $commendation, array $entries): array
    {
        $out = [];

        foreach (DB::table('conduct_entries as e')->leftJoin('conduct_withdrawals as w', 'w.entry_id', '=', 'e.id')->whereNull('w.entry_id')
            ->where('e.commendation', $commendation)->whereBetween('e.happened_on', $range)->whereIn('e.id', $entries ?: ['00000000-0000-0000-0000-000000000000'])->groupBy('e.kind_name')
            ->get(['e.kind_name', DB::raw('count(*) as n')]) as $r) {
            $out[Row::text($r, 'kind_name')] = ['count' => Row::int($r, 'n'), 'commendation' => $commendation];
        }

        return $out;
    }

    /**
     * The classes of this year the viewer may see conduct in, in the place worked in.
     *
     * @return list<string>
     */
    private function seenClasses(User $viewer): array
    {
        $year = $this->calendar->currentYear();
        $out = [];
        foreach ($year === null ? [] : $this->calendar->classesInYear($year['id']) as $c) {
            if ($this->access->allows($viewer, ConductPermission::View, ScopeRef::of(ScopeType::ClassGroup, $c['id']))
                || $this->access->allows($viewer, ConductPermission::FollowUp, ScopeRef::of(ScopeType::ClassGroup, $c['id']))) {
                $out[] = $c['id'];
            }
        }

        return $out;
    }

    private function className(string $classId): string
    {
        return $classId === '' ? '' : ($this->calendar->classNames([$classId])[$classId]['name'] ?? '');
    }
}
