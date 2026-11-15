<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Possible duplicates (FR-LRN-007): the same first name, surname and date of
 * birth, ignoring case. Flagged for a person to review; never merged. A
 * refusal names the other learner only to someone who may see them.
 */
final class Duplicates
{
    public function __construct(
        private readonly Access $access,
        private readonly LearnerPlaces $places,
        private readonly AcademicCalendar $calendar,
    ) {}

    /** The first learner with the same names and date of birth, other than one. */
    public function like(string $first, string $surname, string $born, ?string $except = null): ?string
    {
        $id = DB::table('learners')->whereRaw('lower(first_name) = ?', [mb_strtolower($first)])
            ->whereRaw('lower(surname) = ?', [mb_strtolower($surname)])->where('date_of_birth', $born)
            ->when($except !== null, fn (Builder $q): Builder => $q->where('id', '!=', $except))
            ->orderBy('created_at')->value('id');

        return is_string($id) ? $id : null;
    }

    /** @return array<string, list<string>> the validation messages for a refused admission */
    public function refusal(User $by, string $learnerId): array
    {
        $scope = $this->places->scopeOf($learnerId);

        if ($scope === null || ! $this->access->allows($by, EnrolmentPermission::View, $scope)) {
            return ['duplicate' => ['A learner with the same names and date of birth is already recorded, in a class you cannot see. Admit only if you are sure this is a different learner.']];
        }

        $row = DB::table('learners as l')
            ->leftJoin('learner_identifiers as i', fn (JoinClause $j): JoinClause => $j->on('i.learner_id', '=', 'l.id')->where('i.kind', Numbers::KIND)->whereNull('i.retired_at'))
            ->where('l.id', $learnerId)->first(['l.first_name', 'l.other_names', 'l.surname', 'i.value as number']);
        $place = $this->places->current($learnerId);
        $class = $place !== null ? $this->calendar->classInForce($place['class_id']) : null;
        $name = $row === null ? '' : trim(implode(' ', array_filter([Row::text($row, 'first_name'), Row::maybeText($row, 'other_names'), Row::text($row, 'surname')])));
        $parts = array_filter([$name, $class['name'] ?? null, $row !== null ? Row::maybeText($row, 'number') : null]);

        return [
            'duplicate' => ['A learner with the same names and date of birth is already recorded: '.implode(', ', $parts).'. Records are never merged without a review.'],
            'duplicate_id' => [$learnerId],
        ];
    }

    /**
     * Which of these learners have a possible duplicate among all learners.
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    public function among(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::select('select a.id from learners a where a.id = any(?::uuid[]) and exists (
            select 1 from learners b where b.id <> a.id and lower(b.first_name) = lower(a.first_name)
            and lower(b.surname) = lower(a.surname) and b.date_of_birth = a.date_of_birth)', ['{'.implode(',', $ids).'}']);

        $out = [];

        foreach ($rows as $row) {
            if (is_object($row) && is_string($row->id ?? null)) {
                $out[$row->id] = true;
            }
        }

        return $out;
    }
}
