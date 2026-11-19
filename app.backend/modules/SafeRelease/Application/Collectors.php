<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\Relationship;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may collect a child on a day (D-094): the learner's contacts in force,
 * unless barred, then the collectors the school or family added, for good or
 * for that day only. A one-day collector needs the code the family was given.
 *
 * @phpstan-type MayCollect array{kind: 'contact'|'collector', id: string, name: string, relationship: string, phone: string|null, day_only: bool, needs_code: bool, added_as: string}
 */
final class Collectors
{
    public function __construct(private readonly EnrolmentDirectory $directory) {}

    /** @return list<MayCollect> */
    public function mayCollect(string $learnerId, string $day): array
    {
        $barred = array_column($this->bars($learnerId), 'contact_id');
        $out = [];

        foreach ($this->directory->contactsOf($learnerId) as $c) {
            if (! in_array($c['id'], $barred, true)) {
                $out[] = ['kind' => 'contact', 'id' => $c['id'], 'name' => $c['name'], 'relationship' => Relationship::tryFrom($c['relationship'])?->label() ?? $c['relationship'],
                    'phone' => $c['phone'], 'day_only' => false, 'needs_code' => false, 'added_as' => 'family'];
            }
        }

        foreach (DB::table('release_collectors')->where('learner_id', $learnerId)->whereNull('ended_at')
            ->where(fn (Builder $q): Builder => $q->whereNull('valid_on')->orWhere('valid_on', $day))->orderBy('created_at')->get() as $row) {
            $dayOnly = Row::maybeText($row, 'valid_on') !== null;
            $out[] = ['kind' => 'collector', 'id' => Row::text($row, 'id'), 'name' => Row::text($row, 'name'), 'relationship' => Row::text($row, 'relationship'),
                'phone' => Row::maybeText($row, 'phone'), 'day_only' => $dayOnly, 'needs_code' => $dayOnly && Row::maybeText($row, 'code_hash') !== null,
                'added_as' => Row::text($row, 'added_as')];
        }

        return $out;
    }

    /**
     * The contacts recorded as not to collect, with the reason.
     *
     * @return list<array{id: string, contact_id: string, name: string, reason: string, since: string}>
     */
    public function bars(string $learnerId): array
    {
        $names = [];

        foreach ($this->directory->contactsOf($learnerId) as $c) {
            $names[$c['id']] = $c['name'];
        }

        $out = [];

        foreach (DB::table('release_bars')->where('learner_id', $learnerId)->whereNull('ended_at')->orderBy('created_at')->get() as $row) {
            $contact = Row::text($row, 'contact_id');
            $out[] = ['id' => Row::text($row, 'id'), 'contact_id' => $contact, 'name' => $names[$contact] ?? '', 'reason' => Row::text($row, 'reason'),
                'since' => substr(Row::text($row, 'created_at'), 0, 10)];
        }

        return $out;
    }

    public static function codeHash(string $collectorId, string $code): string
    {
        return hash('sha256', $collectorId.':'.$code);
    }
}
