<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** Learners for other modules, read from the placements: who was in a class on a day. */
final class Directory implements EnrolmentDirectory
{
    public function learnersIn(string $classId, string $day): array
    {
        return array_values(DB::table('placements as p')
            ->join('learners as l', 'l.id', '=', 'p.learner_id')
            ->leftJoin('learner_identifiers as i', fn (JoinClause $j): JoinClause => $j->on('i.learner_id', '=', 'l.id')->where('i.kind', Numbers::KIND)->whereNull('i.retired_at'))
            ->where('p.class_id', $classId)->where('p.starts_on', '<=', $day)
            ->where(fn (Builder $q): Builder => $q->whereNull('p.ends_on')->orWhere('p.ends_on', '>=', $day))
            ->orderBy('l.surname')->orderBy('l.first_name')
            ->get(['l.id', 'l.first_name', 'l.other_names', 'l.surname', 'i.value as number'])
            ->map(fn (object $r): array => [
                'id' => Row::text($r, 'id'),
                'name' => self::name($r),
                'admission_number' => Row::maybeText($r, 'number'),
            ])->all());
    }

    public function names(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('learners')->whereIn('id', array_values(array_unique($ids)))->get(['id', 'first_name', 'other_names', 'surname']) as $r) {
            $out[Row::text($r, 'id')] = self::name($r);
        }

        return $out;
    }

    private static function name(object $r): string
    {
        return trim(implode(' ', array_filter([Row::text($r, 'first_name'), Row::maybeText($r, 'other_names'), Row::text($r, 'surname')])));
    }

    public function contactsOf(string $learnerId): array
    {
        return array_map(fn (array $c): array => ['id' => $c['id'], 'name' => $c['name'], 'relationship' => $c['relationship_label'], 'phone' => $c['phone'],
            'email' => $c['email'], 'notices_by' => $c['notices_by'], 'main' => $c['main']], app(Contacts::class)->of($learnerId));
    }

    public function siblingsOf(string $learnerId): array
    {
        $phones = DB::table('learner_contacts')->where('learner_id', $learnerId)->whereNull('removed_at')->pluck('phone');
        $ids = [];

        foreach (DB::table('learner_contacts')->whereIn('phone', $phones)->whereNull('removed_at')->where('learner_id', '!=', $learnerId)
            ->distinct()->orderBy('learner_id')->pluck('learner_id') as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function classOn(string $learnerId, string $day): ?string
    {
        $class = DB::table('placements')->where('learner_id', $learnerId)->where('starts_on', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $day))
            ->orderByDesc('starts_on')->value('class_id');

        return is_string($class) ? $class : null;
    }

    public function placedNow(): array
    {
        return array_values(array_filter(DB::table('placements')->whereNull('ends_on')->distinct()->pluck('learner_id')->all(), 'is_string'));
    }

    public function placesOf(array $learnerIds): array
    {
        $ids = array_values(array_filter(array_unique($learnerIds), fn (string $id): bool => Ids::isValid($id)));
        $out = [];

        // The same order as LearnerPlaces::current(): an open placement first, then the latest.
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('placements')->whereIn('learner_id', $chunk)
                ->selectRaw('DISTINCT ON (learner_id) learner_id, class_id, division_id, campus_id, year_id, starts_on, ends_on')
                ->orderBy('learner_id')->orderByRaw('ends_on is not null')->orderByDesc('starts_on')->orderByDesc('created_at')->get() as $row) {
                $out[Row::text($row, 'learner_id')] = ['class_id' => Row::text($row, 'class_id'), 'division_id' => Row::text($row, 'division_id'),
                    'campus_id' => Row::text($row, 'campus_id'), 'year_id' => Row::text($row, 'year_id'), 'open' => Row::maybeText($row, 'ends_on') === null,
                    'since' => substr(Row::text($row, 'starts_on'), 0, 10)];
            }
        }

        return $out;
    }

    public function classesOn(array $learnerIds, string $day): array
    {
        $ids = array_values(array_filter(array_unique($learnerIds), fn (string $id): bool => Ids::isValid($id)));
        $out = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('placements')->whereIn('learner_id', $chunk)->where('starts_on', '<=', $day)
                ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $day))
                ->selectRaw('DISTINCT ON (learner_id) learner_id, class_id')->orderBy('learner_id')->orderByDesc('starts_on')->get() as $row) {
                $out[Row::text($row, 'learner_id')] = Row::text($row, 'class_id');
            }
        }

        return $out;
    }

    public function placeOf(string $learnerId): ?array
    {
        $place = app(LearnerPlaces::class)->current($learnerId);

        return $place === null ? null : ['class_id' => $place['class_id'], 'division_id' => $place['division_id'], 'campus_id' => $place['campus_id'],
            'year_id' => $place['year_id'], 'open' => $place['open'], 'since' => $place['starts_on']];
    }
}
