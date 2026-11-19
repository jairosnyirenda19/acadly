<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Application\Actions\ChangeClass;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Boundary;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Grid\BulkResult;
use Acadly\Kernel\Grid\GridColumn;
use Acadly\Kernel\Grid\GridDefinition;
use Acadly\Kernel\Grid\GridFilter;
use Acadly\Kernel\Grid\GridOption;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Learners list on the data grid (the approved design): every active
 * learner in the classes the viewer may see, this year.
 */
final class LearnersGrid implements GridDefinition
{
    /** How many contacts a learner has now. */
    private const string CONTACTS = '(select count(*) from learner_contacts lc where lc.learner_id = l.id and lc.removed_at is null)';

    /** @var array<string, list<array{id: string, name: string, division_id: string, level: string, campus: string, can_admit: bool}>> */
    private array $classes = [];

    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly Learners $learners,
        private readonly Duplicates $duplicates,
        private readonly ChangeClass $changeClass,
    ) {}

    public function key(): string
    {
        return 'learners';
    }

    public function title(): string
    {
        return 'Learners';
    }

    public function query(User $viewer): Builder
    {
        $classes = $this->classesOf($viewer);

        if ($classes === []) {
            $this->access->check($viewer, EnrolmentPermission::View);
        }

        return DB::table('placements as p')
            ->join('enrolments as e', 'e.id', '=', 'p.enrolment_id')
            ->join('learners as l', 'l.id', '=', 'p.learner_id')
            ->leftJoin('learner_identifiers as i', fn (JoinClause $j): JoinClause => $j->on('i.learner_id', '=', 'l.id')->where('i.kind', Numbers::KIND)->whereNull('i.retired_at'))
            ->leftJoin('learner_contacts as c', fn (JoinClause $j): JoinClause => $j->on('c.learner_id', '=', 'l.id')->where('c.is_main', true)->whereNull('c.removed_at'))
            ->whereIn('p.class_id', array_column($classes, 'id') ?: ['00000000-0000-0000-0000-000000000000'])
            ->whereNull('p.ends_on')->where('e.status', 'active')
            ->select(['l.id', 'l.acadly_id', 'l.first_name', 'l.other_names', 'l.surname', 'l.sex', 'l.date_of_birth', 'l.photo_document_id',
                'i.value as number', 'p.class_id', 'e.admitted_on', 'c.name as contact', 'c.phone'])
            ->selectRaw(self::CONTACTS.' as contacts');
    }

    public function idColumn(): string
    {
        return 'l.id';
    }

    public function columns(): array
    {
        return [
            new GridColumn('learner', 'Learner', "lower(l.surname || ' ' || l.first_name)", always: true),
            new GridColumn('admission_number', 'Admission no.', 'i.value'),
            new GridColumn('class', 'Class', "(p.level_code || ':' || p.class_id::text)"),
            new GridColumn('sex', 'Sex', 'l.sex'),
            new GridColumn('date_of_birth', 'Date of birth', 'l.date_of_birth', shown: false),
            new GridColumn('admitted_on', 'Admitted', 'e.admitted_on'),
            new GridColumn('contacts', 'Contacts', self::CONTACTS),
            new GridColumn('main_contact', "Main contact's phone", null, shown: false),
            new GridColumn('campus', 'Campus', null, shown: false),
            new GridColumn('state', 'State', null),
        ];
    }

    public function filters(User $viewer): array
    {
        $classes = $this->classesOf($viewer);

        return [
            GridFilter::choice('class', 'Class', array_map(fn (array $c): GridOption => new GridOption($c['id'], $c['name'],
                fn (Builder $q): Builder => $q->where('p.class_id', $c['id'])), $classes)),
            GridFilter::choice('state', 'State', [
                new GridOption('complete', 'Contacts complete', fn (Builder $q): Builder => $q->whereRaw(self::CONTACTS.' >= ?', [Contacts::MINIMUM])),
                new GridOption('few_contacts', 'One contact only', fn (Builder $q): Builder => $q->whereRaw(self::CONTACTS.' < ?', [Contacts::MINIMUM])),
            ]),
            GridFilter::choice('sex', 'Sex', [
                new GridOption('female', 'Female', fn (Builder $q): Builder => $q->where('l.sex', 'female')),
                new GridOption('male', 'Male', fn (Builder $q): Builder => $q->where('l.sex', 'male')),
            ]),
            GridFilter::dates('admitted', 'Admitted', 'e.admitted_on'),
        ];
    }

    public function search(Builder $query, string $text): void
    {
        $q = '%'.mb_strtolower($text).'%';
        $query->where(fn (Builder $x): Builder => $x
            ->whereRaw("lower(concat_ws(' ', l.first_name, l.other_names, l.surname)) like ?", [$q])
            ->orWhereRaw('lower(i.value) like ?', [$q])
            ->orWhereRaw('lower(l.acadly_id) like ?', [$q]));
    }

    public function defaultSort(): array
    {
        return ['learner', 'asc'];
    }

    public function rows(User $viewer, array $rows): array
    {
        $classes = [];

        foreach ($this->classesOf($viewer) as $c) {
            $classes[$c['id']] = $c;
        }

        $doubles = $this->duplicates->among(array_map(fn (object $r): string => Row::text($r, 'id'), $rows));

        return array_map(function (object $r) use ($classes, $doubles): array {
            $class = $classes[Row::text($r, 'class_id')] ?? null;
            $contacts = Row::int($r, 'contacts');

            return [
                'id' => Row::text($r, 'id'),
                'acadly_id' => Row::text($r, 'acadly_id'),
                'name' => trim(implode(' ', array_filter([Row::text($r, 'first_name'), Row::maybeText($r, 'other_names'), Row::text($r, 'surname')]))),
                'admission_number' => Row::maybeText($r, 'number'),
                'class_id' => Row::text($r, 'class_id'),
                'class' => $class['name'] ?? '',
                'campus' => $class['campus'] ?? '',
                'sex' => Row::text($r, 'sex'),
                'date_of_birth' => substr(Row::text($r, 'date_of_birth'), 0, 10),
                'admitted_on' => Row::maybeText($r, 'admitted_on') !== null ? substr(Row::text($r, 'admitted_on'), 0, 10) : null,
                'contacts' => $contacts,
                'main_contact' => Row::maybeText($r, 'phone'),
                'has_photo' => Row::maybeText($r, 'photo_document_id') !== null,
                'few_contacts' => $contacts < Contacts::MINIMUM,
                'possible_duplicate' => isset($doubles[Row::text($r, 'id')]),
            ];
        }, $rows);
    }

    public function plain(array $row, string $column): string|int|float|null
    {
        $value = match ($column) {
            'learner' => $row['name'] ?? null,
            'state' => ($row['few_contacts'] ?? false) === true ? 'One contact only' : 'Active',
            'sex' => ucfirst(is_string($row['sex'] ?? null) ? $row['sex'] : ''),
            default => $row[$column] ?? null,
        };

        return is_string($value) || is_int($value) || is_float($value) ? $value : null;
    }

    public function canExport(User $viewer): bool
    {
        $classes = $this->classesOf($viewer);

        return $classes !== [] && array_filter($classes, fn (array $c): bool => ! $this->access->allows($viewer, EnrolmentPermission::Export, ScopeRef::of(ScopeType::ClassGroup, $c['id']))) === [];
    }

    public function bulkActions(User $viewer): array
    {
        foreach ($this->classesOf($viewer) as $c) {
            if ($this->access->allows($viewer, EnrolmentPermission::Manage, ScopeRef::of(ScopeType::ClassGroup, $c['id']))) {
                return [['key' => 'change_class', 'label' => 'Change class']];
            }
        }

        return [];
    }

    public function bulk(User $viewer, string $action, array $ids, array $input): BulkResult
    {
        $classId = is_string($input['class_id'] ?? null) ? $input['class_id'] : '';
        $from = is_string($input['from'] ?? null) ? $input['from'] : '';
        $reason = is_string($input['reason'] ?? null) ? $input['reason'] : '';
        $names = DB::table('learners')->whereIn('id', $ids)->get(['id', 'first_name', 'surname'])
            ->mapWithKeys(fn (object $l): array => [Row::text($l, 'id') => Row::text($l, 'first_name').' '.Row::text($l, 'surname')])->all();
        $result = new BulkResult;

        foreach ($ids as $id) {
            try {
                $this->changeClass->handle($viewer, $id, $classId, $from, $reason);
                $result->did();
            } catch (ValidationException $e) {
                $result->refuse($id, is_string($names[$id] ?? null) ? $names[$id] : '', $e->validator->errors()->first());
            }
        }

        return $result;
    }

    /** @return list<array{id: string, name: string, division_id: string, level: string, campus: string, can_admit: bool}> */
    private function classesOf(User $viewer): array
    {
        if (! isset($this->classes[Boundary::cacheKey($viewer)])) {
            $year = $this->calendar->currentYear();
            $this->classes[Boundary::cacheKey($viewer)] = $year === null ? [] : $this->learners->visibleClasses($viewer, $year['id'], null);
        }

        return $this->classes[Boundary::cacheKey($viewer)];
    }
}
