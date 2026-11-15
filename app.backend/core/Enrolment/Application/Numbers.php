<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The school's own admission numbers (FR-LRN-002). A number is issued once
 * and never reused, not even after it is corrected. Left empty, the next
 * number follows the last one issued: its digits at the end go up by one,
 * keeping their width (SA-2026-0414 → SA-2026-0415).
 */
final class Numbers
{
    public const string KIND = 'admission_number';

    public function __construct(private readonly Clock $clock) {}

    public function checkOrNext(?string $value, ?string $learnerId = null): string
    {
        $value = strtoupper(trim((string) $value));

        if ($value === '') {
            return $this->next();
        }

        if (mb_strlen($value) > 40 || preg_match('/^[A-Z0-9][A-Z0-9\/\-. ]*$/', $value) !== 1) {
            throw ValidationException::withMessages(['admission_number' => ['Use letters, digits, spaces and - / . only, up to 40 characters.']]);
        }

        $taken = DB::table('learner_identifiers')->where('kind', self::KIND)->where('value', $value)
            ->when($learnerId !== null, fn (Builder $q): Builder => $q->where(fn (Builder $w): Builder => $w->where('learner_id', '!=', $learnerId)->orWhereNotNull('retired_at')))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['admission_number' => ['This admission number has already been issued. A number is never reused.']]);
        }

        return $value;
    }

    public function next(): string
    {
        $last = DB::table('learner_identifiers')->where('kind', self::KIND)->orderByDesc('issued_at')->value('value');

        if (! is_string($last) || preg_match('/^(.*?)(\d+)$/', $last, $m) !== 1) {
            $candidate = $this->clock->now()->format('Y').'-0001';
        } else {
            $candidate = $m[1].str_pad((string) ((int) $m[2] + 1), strlen($m[2]), '0', STR_PAD_LEFT);
        }

        while (DB::table('learner_identifiers')->where('kind', self::KIND)->where('value', $candidate)->exists()) {
            preg_match('/^(.*?)(\d+)$/', $candidate, $m);
            $candidate = ($m[1] ?? '').str_pad((string) ((int) ($m[2] ?? 0) + 1), strlen($m[2] ?? '1'), '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    /** Issues a number to a learner, retiring the one in force. */
    public function issue(User $by, string $learnerId, string $value): void
    {
        $now = $this->clock->now();
        DB::table('learner_identifiers')->where('learner_id', $learnerId)->where('kind', self::KIND)->whereNull('retired_at')->update(['retired_at' => $now]);
        DB::table('learner_identifiers')->insert(['id' => Ids::next(), 'learner_id' => $learnerId, 'kind' => self::KIND, 'value' => $value,
            'issued_at' => $now, 'issued_by' => $by->id]);
    }

    public function of(string $learnerId): ?string
    {
        $value = DB::table('learner_identifiers')->where('learner_id', $learnerId)->where('kind', self::KIND)->whereNull('retired_at')->value('value');

        return is_string($value) ? $value : null;
    }
}
