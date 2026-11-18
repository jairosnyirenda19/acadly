<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Search;

use Acadly\Core\Enrolment\Application\Numbers;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Search\SearchHit;
use Acadly\Kernel\Search\SearchQuery;
use Acadly\Kernel\Search\SearchSource;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Learners (12-search §6): the learners in the classes the reader's learner
 * list shows, by name, admission number or Acadly ID. Never by date of birth,
 * contacts, notes or marks.
 */
final class LearnersSearch implements SearchSource
{
    public function __construct(private readonly VisibleClasses $classes) {}

    public function key(): string
    {
        return 'learners';
    }

    public function module(): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Learners';
    }

    public function position(): int
    {
        return 20;
    }

    public function available(User $who): bool
    {
        return $this->classes->of($who) !== [];
    }

    public function search(User $who, SearchQuery $query): array
    {
        $classes = [];

        foreach ($this->classes->of($who) as $c) {
            $classes[$c['id']] = $c;
        }

        $name = "l.first_name || ' ' || coalesce(l.other_names, '') || ' ' || l.surname";
        $reference = $query->reference();
        $base = DB::table('learners as l')
            ->join('placements as p', 'p.learner_id', '=', 'l.id')
            ->join('enrolments as e', 'e.id', '=', 'p.enrolment_id')
            ->leftJoin('learner_identifiers as n', fn (JoinClause $j): JoinClause => $j->on('n.learner_id', '=', 'l.id')->where('n.kind', '=', Numbers::KIND)->whereNull('n.retired_at'))
            ->whereIn('p.class_id', array_keys($classes) ?: ['00000000-0000-0000-0000-000000000000'])->whereNull('p.ends_on')->where('e.status', 'active')
            ->where(fn (Builder $w): Builder => $w->where(fn (Builder $a): Builder => $query->words($a, $name))
                ->orWhereRaw("upper(coalesce(n.value, '')) like ?", [$reference.'%'])->orWhereRaw('upper(l.acadly_id) like ?', [$reference.'%']))
            ->orderByRaw("case when upper(coalesce(n.value, '')) = ? or upper(l.acadly_id) = ? then 0 else 1 end", [$reference, $reference]);
        $rows = $query->startsFirst($base, "l.first_name || ' ' || l.surname")->orderBy('l.surname')->orderBy('l.first_name')
            ->offset($query->offset)->limit($query->limit + 1)
            ->get(['l.id', 'l.first_name', 'l.surname', 'l.acadly_id', 'n.value as number', 'p.class_id']);

        return array_values(array_map(function (object $r) use ($classes): SearchHit {
            $class = $classes[Row::text($r, 'class_id')] ?? null;

            return new SearchHit(Row::text($r, 'id'), 'learner', Row::text($r, 'first_name').' '.Row::text($r, 'surname'),
                Row::maybeText($r, 'number') ?? Row::text($r, 'acadly_id'), $class !== null ? $class['name'].' · '.$class['campus'] : null, 'Active');
        }, $rows->all()));
    }
}
