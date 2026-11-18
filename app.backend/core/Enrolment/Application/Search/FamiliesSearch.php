<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Search;

use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Contracts\Relationship;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Search\SearchHit;
use Acadly\Kernel\Search\SearchQuery;
use Acadly\Kernel\Search\SearchSource;
use Acadly\Kernel\Support\Phone;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * Families (12-search §6, rule 12): for the office that keeps families, the
 * contacts of learners in its classes, by name, or by phone number as an
 * exact or starting match on the whole number. A hit opens the learner.
 */
final class FamiliesSearch implements SearchSource
{
    public function __construct(
        private readonly VisibleClasses $classes,
        private readonly Access $access,
    ) {}

    public function key(): string
    {
        return 'families';
    }

    public function module(): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Families';
    }

    public function position(): int
    {
        return 40;
    }

    public function available(User $who): bool
    {
        return $this->access->allows($who, EnrolmentPermission::Families) && $this->classes->of($who) !== [];
    }

    public function search(User $who, SearchQuery $query): array
    {
        $ids = array_column($this->classes->of($who), 'id');
        $digits = (string) preg_replace('/\D/', '', $query->text);
        $local = strlen($digits) >= 6 && strlen($digits) === strlen(str_replace([' ', '+'], '', $query->text))
            ? substr((string) preg_replace('/^(265|0)/', '', $digits), 0, 9) : null;
        $q = DB::table('learner_contacts as c')->join('learners as l', 'l.id', '=', 'c.learner_id')
            ->join('placements as p', 'p.learner_id', '=', 'l.id')->join('enrolments as e', 'e.id', '=', 'p.enrolment_id')
            ->whereIn('p.class_id', $ids ?: ['00000000-0000-0000-0000-000000000000'])->whereNull('p.ends_on')->where('e.status', 'active')->whereNull('c.removed_at');
        $local !== null
            ? $q->whereRaw("right(regexp_replace(c.phone, '[^0-9]', '', 'g'), 9) like ?", [$local.'%'])
            : $query->words($q, 'c.name');
        $rows = $query->startsFirst($q, 'c.name')->orderBy('c.name')->offset($query->offset)->limit($query->limit + 1)
            ->get(['c.name', 'c.relationship', 'c.phone', 'l.id', 'l.first_name', 'l.surname']);

        return array_values(array_map(fn (object $r): SearchHit => new SearchHit(Row::text($r, 'id'), 'learner', Row::text($r, 'name'),
            Phone::forPeople(Row::text($r, 'phone')), (Relationship::tryFrom(Row::text($r, 'relationship'))?->label() ?? 'Contact').' of '.Row::text($r, 'first_name').' '.Row::text($r, 'surname')), $rows->all()));
    }
}
