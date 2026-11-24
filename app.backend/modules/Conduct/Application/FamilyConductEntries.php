<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Conduct\Application\Actions\CaseActions;
use Acadly\Modules\Conduct\Journeys\SeriousIncident\State;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What a family may read of a learner's conduct (D-110): the entries the
 * family was told about this school year, never withdrawn ones, and whether
 * the school shows conduct to families where the learner is placed.
 *
 * @phpstan-type FamilyEntry array{id: string, kind: string, commendation: bool, serious: bool, happened_on: string, this_term: bool}
 */
final class FamilyConductEntries
{
    public function __construct(
        private readonly EnrolmentDirectory $directory,
        private readonly AcademicCalendar $calendar,
        private readonly ModuleActivation $activation,
        private readonly Clock $clock,
    ) {}

    /**
     * The entries the family was told about this school year, newest first, and the serious incidents among them.
     *
     * @return array{term: string|null, entries: list<FamilyEntry>, cases: list<array{entry_id: string, kind: string, happened_on: string, state: string, decision: string|null}>}
     */
    public function read(string $learnerId): array
    {
        $today = $this->clock->today();
        $year = $this->calendar->currentYear();
        $division = $this->directory->placeOf($learnerId)['division_id'] ?? null;

        if ($year === null || $division === null) {
            return ['term' => null, 'entries' => [], 'cases' => []];
        }

        $term = null;

        foreach ($year['terms'][$division] ?? [] as $t) {
            if ($t['starts_on'] <= $today && $t['ends_on'] >= $today) {
                $term = $t;
            }
        }

        $rows = DB::table('conduct_entries as e')->join('conduct_entry_learners as l', 'l.entry_id', '=', 'e.id')
            ->leftJoin('conduct_withdrawals as w', 'w.entry_id', '=', 'e.id')->leftJoin('conduct_cases as c', 'c.entry_id', '=', 'e.id')
            ->where('l.learner_id', $learnerId)->where('e.told_family', true)->whereNull('w.entry_id')
            ->whereBetween('e.happened_on', [$year['starts_on'], $today])
            ->where(fn (Builder $q): Builder => $q->whereNull('c.state')->orWhere('c.state', '!=', State::Withdrawn->value))
            ->orderByDesc('e.happened_on')->orderByDesc('e.created_at')->limit(200)
            ->get(['e.id', 'e.kind_name', 'e.commendation', 'e.level', 'e.happened_on', 'c.id as case_id', 'c.state']);
        $entries = [];
        $cases = [];

        foreach ($rows as $r) {
            $on = Row::text($r, 'happened_on');
            $entries[] = ['id' => Row::text($r, 'id'), 'kind' => Row::text($r, 'kind_name'), 'commendation' => (bool) $r->commendation,
                'serious' => Row::maybeText($r, 'level') === 'serious', 'happened_on' => $on,
                'this_term' => $term !== null && $on >= $term['starts_on'] && $on <= $term['ends_on']];
            $caseId = Row::maybeText($r, 'case_id');

            if ($caseId !== null) {
                $outcome = CaseActions::latestOutcomes($caseId)[$learnerId] ?? null;
                $cases[] = ['entry_id' => Row::text($r, 'id'), 'kind' => Row::text($r, 'kind_name'), 'happened_on' => $on, 'state' => Row::text($r, 'state'),
                    'decision' => $outcome === null ? null : CaseActions::describe($outcome)];
            }
        }

        return ['term' => $term['name'] ?? null, 'entries' => $entries, 'cases' => $cases];
    }

    /** The school shows conduct to families, and Conduct is on where the child is placed. */
    public function shownFor(?string $classId): bool
    {
        return ConductViews::rules()['portal'] && $classId !== null && $this->activation->isActive('conduct', ScopeRef::of(ScopeType::ClassGroup, $classId));
    }
}
