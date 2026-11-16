<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Grid\BulkResult;
use Acadly\Kernel\Grid\GridColumn;
use Acadly\Kernel\Grid\GridDefinition;
use Acadly\Kernel\Grid\GridFilter;
use Acadly\Kernel\Grid\GridOption;
use Acadly\Kernel\Http\Problem;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Application\Actions\MarksSteps;
use Acadly\Modules\Assessment\Contracts\AssessmentKind;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Journeys\Marks\State;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The office's list of assessments on the data grid (the approved Marks
 * office design): every assessment this year in the classes the viewer may
 * see, with the marks waiting to be locked or returned. Bulk lock goes
 * through the journey, row by row, and reports each row it could not lock.
 */
final class AssessmentsGrid implements GridDefinition
{
    /** Learners with a mark or a reason. */
    private const string MARKED = '(select count(distinct m.learner_id) from assessment_marks m where m.assessment_id = a.id)';

    /** The mean of the marks in force, as a percentage. */
    private const string AVERAGE = '(select round(avg(x.value) / a.out_of * 100, 1) from (select distinct on (m.learner_id) m.value from assessment_marks m '
        .'where m.assessment_id = a.id order by m.learner_id, m.entered_at desc, m.id desc) x where x.value is not null)';

    public function __construct(
        private readonly Access $access,
        private readonly Reach $reach,
        private readonly Teaching $teaching,
        private readonly EnrolmentDirectory $learners,
        private readonly MarksSteps $steps,
    ) {}

    public function key(): string
    {
        return 'assessments';
    }

    public function title(): string
    {
        return 'Assessments';
    }

    public function query(User $viewer): Builder
    {
        $classes = $this->reach->classes($viewer);

        if ($classes === []) {
            $this->access->check($viewer, AssessmentPermission::View);
        }

        return DB::table('assessments as a')
            ->join('academic_classes as k', 'k.id', '=', 'a.class_id')
            ->join('academic_subjects as s', 's.id', '=', 'a.subject_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.created_by')
            ->whereIn('a.class_id', array_column($classes, 'id') ?: [Plans::NONE])
            ->select(['a.id', 'a.class_id', 'a.subject_id', 'a.kind', 'a.title', 'a.held_on', 'a.out_of', 'a.state', 'a.counts',
                'k.name as class_name', 'k.level_code', 'k.division_id', 's.name as subject_name', 'u.name as teacher'])
            ->selectRaw(self::MARKED.' as marked')
            ->selectRaw(self::AVERAGE.' as average');
    }

    public function idColumn(): string
    {
        return 'a.id';
    }

    public function columns(): array
    {
        return [
            new GridColumn('assessment', 'Assessment', "lower(s.name || ' ' || a.title)", always: true),
            new GridColumn('class', 'Class', "(k.level_code || ':' || lower(k.name))"),
            new GridColumn('teacher', 'Teacher', 'lower(u.name)'),
            new GridColumn('held_on', 'Held', 'a.held_on'),
            new GridColumn('marked', 'Marked', self::MARKED),
            new GridColumn('average', 'Average', self::AVERAGE),
            new GridColumn('state', 'State', 'a.state'),
        ];
    }

    public function filters(User $viewer): array
    {
        $classes = $this->reach->classes($viewer);
        $levels = [];
        $subjects = [];

        foreach ($classes as $c) {
            $levels[$c['division_id'].':'.$c['level_code']] ??= new GridOption($c['division_id'].':'.$c['level_code'], $this->reach->levelLabel($c['division_id'], $c['level_code']),
                fn (Builder $q): Builder => $q->where('k.division_id', $c['division_id'])->where('k.level_code', $c['level_code']));

            foreach ($this->teaching->subjectsFor($c['division_id'], $c['level_code']) as $s) {
                $subjects[$s['id']] ??= new GridOption($s['id'], $s['name'], fn (Builder $q): Builder => $q->where('a.subject_id', $s['id']));
            }
        }

        return [
            GridFilter::choice('state', 'State', array_map(fn (State $s): GridOption => new GridOption($s->value, $s->label(),
                fn (Builder $q): Builder => $q->where('a.state', $s->value)), State::cases())),
            GridFilter::choice('level', 'Level', array_values($levels)),
            GridFilter::choice('subject', 'Subject', array_values($subjects)),
            GridFilter::choice('kind', 'Kind', array_map(fn (AssessmentKind $k): GridOption => new GridOption($k->value, $k->label(),
                fn (Builder $q): Builder => $q->where('a.kind', $k->value)), AssessmentKind::cases())),
            GridFilter::dates('held', 'Held', 'a.held_on'),
        ];
    }

    public function search(Builder $query, string $text): void
    {
        $q = '%'.mb_strtolower($text).'%';
        $query->where(fn (Builder $x): Builder => $x
            ->whereRaw('lower(a.title) like ?', [$q])
            ->orWhereRaw('lower(s.name) like ?', [$q])
            ->orWhereRaw('lower(k.name) like ?', [$q])
            ->orWhereRaw('lower(u.name) like ?', [$q]));
    }

    public function defaultSort(): array
    {
        return ['held_on', 'desc'];
    }

    public function rows(User $viewer, array $rows): array
    {
        return array_map(function (object $r): array {
            $state = State::tryFrom(Row::text($r, 'state')) ?? State::Marking;
            $held = substr(Row::text($r, 'held_on'), 0, 10);
            $average = $r->average ?? null;

            return [
                'id' => Row::text($r, 'id'),
                'class_id' => Row::text($r, 'class_id'),
                'class' => Row::text($r, 'class_name'),
                'level' => $this->reach->levelLabel(Row::text($r, 'division_id'), Row::text($r, 'level_code')),
                'subject' => Row::text($r, 'subject_name'),
                'kind' => Row::text($r, 'kind'),
                'kind_label' => AssessmentKind::tryFrom(Row::text($r, 'kind'))?->label() ?? '',
                'title' => Row::text($r, 'title'),
                'teacher' => Row::maybeText($r, 'teacher'),
                'held_on' => $held,
                'out_of' => Row::int($r, 'out_of'),
                'counts' => (bool) ($r->counts ?? false),
                'marked' => Row::int($r, 'marked'),
                'learners' => count($this->learners->learnersIn(Row::text($r, 'class_id'), $held)),
                'average' => is_numeric($average) ? (float) $average : null,
                'state' => $state->value,
                'state_label' => $state->label(),
            ];
        }, $rows);
    }

    public function plain(array $row, string $column): string|int|float|null
    {
        $value = match ($column) {
            'assessment' => implode(' · ', array_filter([$row['subject'] ?? null, $row['kind_label'] ?? null, $row['title'] ?? null], is_string(...))),
            'marked' => (is_int($row['marked'] ?? null) ? $row['marked'] : 0).'/'.(is_int($row['learners'] ?? null) ? $row['learners'] : 0),
            'state' => $row['state_label'] ?? null,
            default => $row[$column] ?? null,
        };

        return is_string($value) || is_int($value) || is_float($value) ? $value : null;
    }

    public function canExport(User $viewer): bool
    {
        $classes = $this->reach->classes($viewer);

        return $classes !== [] && array_filter($classes, fn (array $c): bool => ! $this->reach->may($viewer, AssessmentPermission::View, $c['id'])
            && ! $this->reach->may($viewer, AssessmentPermission::Lock, $c['id']) && ! $this->reach->may($viewer, AssessmentPermission::Plan, $c['id'])) === [];
    }

    public function bulkActions(User $viewer): array
    {
        foreach ($this->reach->classes($viewer) as $c) {
            if ($this->reach->may($viewer, AssessmentPermission::Lock, $c['id'])) {
                return [['key' => 'lock', 'label' => 'Lock the marks']];
            }
        }

        return [];
    }

    public function bulk(User $viewer, string $action, array $ids, array $input): BulkResult
    {
        $rows = DB::table('assessments as a')->join('academic_subjects as s', 's.id', '=', 'a.subject_id')->whereIn('a.id', $ids)
            ->get(['a.id', 'a.title', 'a.state', 's.name'])->keyBy('id')->all();
        $result = new BulkResult;

        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            $name = $row !== null ? Row::text($row, 'name').' · '.Row::text($row, 'title') : '';

            if ($row === null || Row::text($row, 'state') !== State::Submitted->value) {
                $result->refuse($id, $name, 'The marks are not submitted.');

                continue;
            }

            try {
                $this->steps->lock($viewer, $id);
                $result->did();
            } catch (Problem $e) {
                $result->refuse($id, $name, $e->problemCode === 'another_person_required' ? 'You cannot lock marks you submitted.' : $e->title);
            } catch (AuthorizationException|NotFoundHttpException) {
                $result->refuse($id, $name, 'You may not lock the marks of this class.');
            } catch (ValidationException $e) {
                $result->refuse($id, $name, $e->validator->errors()->first());
            }
        }

        return $result;
    }
}
