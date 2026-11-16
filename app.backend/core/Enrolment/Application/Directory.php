<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
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
}
