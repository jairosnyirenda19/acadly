<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Contracts\Relationship;
use Acadly\Core\Enrolment\Domain\Learner;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Core\Institution\Contracts\Term;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * What the Learners screens show (the approved designs). A viewer sees the
 * learners in the classes where they hold "See learners", narrowed to the
 * place they work in. The word for a learner comes from the level: Child,
 * Pupil or Student; "Learner" when the list spans levels.
 */
final class Learners
{
    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly SchoolDirectory $school,
        private readonly LearnerPlaces $places,
        private readonly Contacts $contacts,
        private readonly Numbers $numbers,
        private readonly Duplicates $duplicates,
    ) {}

    /**
     * @return array{year: array{id: string, name: string}|null, word: string, word_plural: string, classes: list<array{id: string, name: string, level: string, campus: string, count: int, can_admit: bool}>, learners: list<array<string, mixed>>, totals: array{active: int, admitted_this_year: int, classes: int, few_contacts: int, duplicates: int}, can_admit: bool, relationships: list<array{value: string, label: string}>}
     */
    public function list(User $viewer, ?ScopeRef $in, ?string $classId, string $query): array
    {
        $year = $this->calendar->currentYear();
        $classes = $year === null ? [] : $this->visibleClasses($viewer, $year['id'], $in);

        if ($classes === []) {
            $this->access->check($viewer, EnrolmentPermission::View, $in);
        }

        $ids = array_column($classes, 'id');
        $counts = $ids === [] ? [] : DB::table('placements as p')->join('enrolments as e', 'e.id', '=', 'p.enrolment_id')
            ->whereIn('p.class_id', $ids)->whereNull('p.ends_on')->where('e.status', 'active')
            ->groupBy('p.class_id')->selectRaw('p.class_id, count(*) as n')->pluck('n', 'class_id')->all();
        $chosen = $classId !== null && in_array($classId, $ids, true) ? [$classId] : $ids;
        $rows = $this->rows($chosen, array_column($classes, 'name', 'id'), $query);
        $learnerIds = array_column($rows, 'id');
        $contactCounts = $this->contactCounts($learnerIds);
        $doubles = $this->duplicates->among($learnerIds);
        $allIds = $ids === [] ? [] : $this->activeIn($ids);
        $allContacts = $this->contactCounts($allIds);
        $allDoubles = $this->duplicates->among($allIds);
        $divisions = array_values(array_unique(array_column($classes, 'division_id')));
        $profile = count($divisions) === 1 ? $this->school->division($divisions[0])?->profile : null;

        return [
            'year' => $year === null ? null : ['id' => $year['id'], 'name' => $year['name']],
            'word' => $profile?->word(Term::Learner->value) ?? 'Learner',
            'word_plural' => $profile?->word(Term::Learner->value, true) ?? 'Learners',
            'classes' => array_map(fn (array $c): array => [
                'id' => $c['id'], 'name' => $c['name'], 'level' => $c['level'], 'campus' => $c['campus'],
                'count' => is_numeric($counts[$c['id']] ?? null) ? (int) $counts[$c['id']] : 0, 'can_admit' => $c['can_admit'],
            ], $classes),
            'learners' => array_map(fn (array $r): array => [
                ...$r,
                'contacts' => $contactCounts[$r['id']] ?? 0,
                'possible_duplicate' => isset($doubles[$r['id']]),
            ], $rows),
            'totals' => [
                'active' => count($allIds),
                'admitted_this_year' => $year === null || $allIds === [] ? 0 : DB::table('enrolments')->whereIn('learner_id', $allIds)
                    ->where('status', 'active')->where('admitted_on', '>=', $year['starts_on'])->count(),
                'classes' => count($classes),
                'few_contacts' => count(array_filter($allIds, fn (string $id): bool => ($allContacts[$id] ?? 0) < Contacts::MINIMUM)),
                'duplicates' => count($allDoubles),
            ],
            'can_admit' => array_filter($classes, fn (array $c): bool => $c['can_admit']) !== [],
            'relationships' => array_map(fn (Relationship $r): array => ['value' => $r->value, 'label' => $r->label()], Relationship::cases()),
        ];
    }

    /** @return array<string, mixed> */
    public function record(User $viewer, Learner $learner): array
    {
        $place = $this->places->current($learner->id);
        $class = $place !== null ? $this->calendar->classInForce($place['class_id']) : null;
        $at = $place !== null ? ScopeRef::of(ScopeType::ClassGroup, $place['class_id']) : null;
        $division = $place !== null ? $this->school->division($place['division_id']) : null;
        $teacher = $class !== null ? $this->calendar->classTeacherId($class['id']) : null;
        $enrolment = $place !== null ? DB::table('enrolments')->where('id', $place['enrolment_id'])->first(['status', 'admitted_on']) : null;
        $siblings = $class === null ? [] : array_values(array_filter($this->calendar->classesInYear($class['year_id']),
            fn (array $c): bool => $c['id'] !== $class['id'] && $c['campus_id'] === $class['campus_id'] && $c['level_code'] === $class['level_code']
                && $this->access->allows($viewer, EnrolmentPermission::Manage, ScopeRef::of(ScopeType::ClassGroup, $c['id']))));
        $siblingCounts = $siblings === [] ? [] : DB::table('placements')->whereIn('class_id', array_column($siblings, 'id'))->whereNull('ends_on')
            ->groupBy('class_id')->selectRaw('class_id, count(*) as n')->pluck('n', 'class_id')->all();

        return [
            'id' => $learner->id,
            'acadly_id' => $learner->acadly_id,
            'admission_number' => $this->numbers->of($learner->id),
            'first_name' => $learner->first_name,
            'other_names' => $learner->other_names,
            'surname' => $learner->surname,
            'name' => $learner->name(),
            'sex' => $learner->sex,
            'date_of_birth' => $learner->date_of_birth->toDateString(),
            'has_photo' => $learner->photo_document_id !== null,
            'status' => $enrolment !== null ? Row::text($enrolment, 'status') : 'active',
            'admitted_on' => $enrolment !== null ? substr(Row::text($enrolment, 'admitted_on'), 0, 10) : null,
            'word' => $division?->profile->word(Term::Learner->value) ?? 'Learner',
            'class' => $class === null ? null : ['id' => $class['id'], 'name' => $class['name']],
            'level' => $place !== null ? $division?->profile->levelLabel($place['level_code']) : null,
            'division' => $division?->name,
            'campus' => $place !== null ? $this->school->campus($place['campus_id'])?->name : null,
            'class_teacher' => $teacher !== null ? self::userName($teacher) : null,
            'placements' => $this->placements($learner->id),
            'contacts' => $this->contacts->of($learner->id),
            'activity' => $this->activity($learner->id),
            'possible_duplicate' => $this->duplicates->like($learner->first_name, $learner->surname, $learner->date_of_birth->toDateString(), $learner->id) !== null,
            'move_to' => array_map(fn (array $c): array => ['id' => $c['id'], 'name' => $c['name'], 'count' => is_numeric($siblingCounts[$c['id']] ?? null) ? (int) $siblingCounts[$c['id']] : 0], $siblings),
            'can_manage' => $at !== null && $this->access->allows($viewer, EnrolmentPermission::Manage, $at),
            'relationships' => array_map(fn (Relationship $r): array => ['value' => $r->value, 'label' => $r->label()], Relationship::cases()),
        ];
    }

    /** @return list<array{id: string, name: string, division_id: string, level: string, campus: string, can_admit: bool}> */
    private function visibleClasses(User $viewer, string $yearId, ?ScopeRef $in): array
    {
        $out = [];
        $campuses = [];

        foreach ($this->school->campusViews() as $campus) {
            $campuses[$campus->id] = $campus->name;
        }

        foreach ($this->calendar->classesInYear($yearId) as $class) {
            $at = ScopeRef::of(ScopeType::ClassGroup, $class['id']);

            if (! $this->access->allows($viewer, EnrolmentPermission::View, $at) || ($in !== null && ! $this->within($at, $in))) {
                continue;
            }

            $division = $this->school->division($class['division_id']);
            $out[] = [
                'id' => $class['id'], 'name' => $class['name'], 'division_id' => $class['division_id'],
                'level' => $division?->profile->levelLabel($class['level_code']) ?? $class['level_code'],
                'campus' => $campuses[$class['campus_id']] ?? '',
                'can_admit' => $this->access->allows($viewer, EnrolmentPermission::Admit, $at),
            ];
        }

        return $out;
    }

    private function within(ScopeRef $at, ScopeRef $in): bool
    {
        foreach ([$at, ...$this->access->ancestors($at)] as $scope) {
            if ($scope->type === $in->type && $scope->id === $in->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $classIds
     * @param  array<string, string>  $names
     * @return list<array{id: string, acadly_id: string, name: string, admission_number: string|null, class_id: string, class: string, has_photo: bool, main_contact: array{name: string, relationship: string, phone: string}|null}>
     */
    private function rows(array $classIds, array $names, string $query): array
    {
        if ($classIds === []) {
            return [];
        }

        $q = mb_strtolower(trim($query));
        $rows = DB::table('placements as p')
            ->join('enrolments as e', 'e.id', '=', 'p.enrolment_id')
            ->join('learners as l', 'l.id', '=', 'p.learner_id')
            ->leftJoin('learner_identifiers as i', fn (JoinClause $j): JoinClause => $j->on('i.learner_id', '=', 'l.id')->where('i.kind', Numbers::KIND)->whereNull('i.retired_at'))
            ->leftJoin('learner_contacts as c', fn (JoinClause $j): JoinClause => $j->on('c.learner_id', '=', 'l.id')->where('c.is_main', true)->whereNull('c.removed_at'))
            ->whereIn('p.class_id', $classIds)->whereNull('p.ends_on')->where('e.status', 'active')
            ->when($q !== '', fn (Builder $w): Builder => $w->where(fn (Builder $x): Builder => $x
                ->whereRaw("lower(concat_ws(' ', l.first_name, l.other_names, l.surname)) like ?", ['%'.$q.'%'])
                ->orWhereRaw('lower(i.value) like ?', ['%'.$q.'%'])
                ->orWhereRaw('lower(l.acadly_id) like ?', ['%'.$q.'%'])))
            ->orderBy('l.surname')->orderBy('l.first_name')->limit(1000)
            ->get(['l.id', 'l.acadly_id', 'l.first_name', 'l.other_names', 'l.surname', 'l.photo_document_id', 'i.value as number',
                'p.class_id', 'c.name as contact', 'c.relationship', 'c.phone']);

        return array_values($rows->map(fn (object $r): array => [
            'id' => Row::text($r, 'id'),
            'acadly_id' => Row::text($r, 'acadly_id'),
            'name' => trim(implode(' ', array_filter([Row::text($r, 'first_name'), Row::maybeText($r, 'other_names'), Row::text($r, 'surname')]))),
            'admission_number' => Row::maybeText($r, 'number'),
            'class_id' => Row::text($r, 'class_id'),
            'class' => $names[Row::text($r, 'class_id')] ?? '',
            'has_photo' => Row::maybeText($r, 'photo_document_id') !== null,
            'main_contact' => Row::maybeText($r, 'contact') === null ? null : [
                'name' => Row::text($r, 'contact'),
                'relationship' => Relationship::tryFrom(Row::text($r, 'relationship'))?->label() ?? '',
                'phone' => Row::text($r, 'phone'),
            ],
        ])->all());
    }

    /**
     * @param  list<string>  $classIds
     * @return list<string> the learners active in these classes now
     */
    private function activeIn(array $classIds): array
    {
        return array_values(array_filter(DB::table('placements as p')->join('enrolments as e', 'e.id', '=', 'p.enrolment_id')
            ->whereIn('p.class_id', $classIds)->whereNull('p.ends_on')->where('e.status', 'active')->pluck('p.learner_id')->all(), is_string(...)));
    }

    /**
     * @param  list<string>  $learnerIds
     * @return array<string, int>
     */
    private function contactCounts(array $learnerIds): array
    {
        if ($learnerIds === []) {
            return [];
        }

        $out = [];

        foreach (array_chunk($learnerIds, 5000) as $chunk) {
            foreach (DB::table('learner_contacts')->whereIn('learner_id', $chunk)->whereNull('removed_at')
                ->groupBy('learner_id')->selectRaw('learner_id, count(*) as n')->get() as $row) {
                $out[Row::text($row, 'learner_id')] = Row::int($row, 'n');
            }
        }

        return $out;
    }

    /** @return list<array{class: string, campus: string, year: string, starts_on: string, ends_on: string|null, entry_reason: string, reason: string|null, now: bool}> */
    private function placements(string $learnerId): array
    {
        $campuses = [];

        foreach ($this->school->campusViews() as $campus) {
            $campuses[$campus->id] = $campus->name;
        }

        $rows = DB::table('placements')->where('learner_id', $learnerId)->orderByDesc('starts_on')->orderByDesc('created_at')
            ->get(['class_id', 'campus_id', 'starts_on', 'ends_on', 'entry_reason', 'reason']);
        $names = $this->calendar->classNames(array_values(array_map(fn (object $r): string => Row::text($r, 'class_id'), $rows->all())));

        return array_values($rows
            ->map(fn (object $r): array => [
                'class' => $names[Row::text($r, 'class_id')]['name'] ?? '',
                'campus' => $campuses[Row::text($r, 'campus_id')] ?? '',
                'year' => $names[Row::text($r, 'class_id')]['year'] ?? '',
                'starts_on' => substr(Row::text($r, 'starts_on'), 0, 10),
                'ends_on' => Row::maybeText($r, 'ends_on') !== null ? substr(Row::text($r, 'ends_on'), 0, 10) : null,
                'entry_reason' => Row::text($r, 'entry_reason'),
                'reason' => Row::maybeText($r, 'reason'),
                'now' => Row::maybeText($r, 'ends_on') === null,
            ])->all());
    }

    /** @return list<array{at: string, what: string, who: string, reason: string|null}> */
    private function activity(string $learnerId): array
    {
        return array_values(DB::table('audit_events')->where('subject_type', 'learner')->where('subject_id', $learnerId)
            ->orderByDesc('seq')->limit(20)->get(['occurred_at', 'action', 'actor_label', 'reason'])
            ->map(fn (object $r): array => [
                'at' => Row::text($r, 'occurred_at'),
                'what' => EnrolmentAudit::tryFrom(Row::text($r, 'action'))?->sentence() ?? '',
                'who' => Row::text($r, 'actor_label'),
                'reason' => Row::maybeText($r, 'reason'),
            ])->all());
    }

    private static function userName(string $id): ?string
    {
        $name = DB::table('users')->where('id', $id)->value('name');

        return is_string($name) ? $name : null;
    }
}
