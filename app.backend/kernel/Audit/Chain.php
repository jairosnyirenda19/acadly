<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

use Carbon\CarbonImmutable;

/**
 * The hash chain of the audit trail (docs/architecture/10-audit-trail.md §5).
 *
 * `hash = sha256(prev_hash ‖ canonical(line))`. The canonical form is built
 * from the stored columns, so verification needs nothing but the table.
 */
final class Chain
{
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Every column the hash covers, in a fixed order. */
    public const array COLUMNS = [
        'id', 'seq', 'occurred_at', 'division_id', 'campus_id', 'module', 'action', 'outcome', 'refusal',
        'actor_user_id', 'actor_kind', 'actor_label', 'actor_roles', 'acting_for_user_id', 'support_grant_id',
        'session_ref', 'request_id', 'device_id', 'ip', 'user_agent', 'channel',
        'subject_type', 'subject_id', 'subject_label', 'about_person_id',
        'changes', 'payload_id', 'payload_hash', 'reason', 'journey_ref', 'journey_step', 'journey_outcome', 'meta',
    ];

    public const array JSON_COLUMNS = ['actor_roles', 'changes', 'meta'];

    /** @param  array<string, mixed>  $line */
    public static function hash(string $previous, array $line): string
    {
        return hash('sha256', $previous.self::canonical($line));
    }

    /**
     * One line as a stable string: the covered columns in order, JSON values
     * decoded and their keys sorted, times as UTC with microseconds.
     *
     * @param  array<string, mixed>  $line
     */
    public static function canonical(array $line): string
    {
        $out = [];

        foreach (self::COLUMNS as $column) {
            $value = $line[$column] ?? null;

            if (in_array($column, self::JSON_COLUMNS, true)) {
                $value = self::sorted(is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : ($value ?? []));
            } elseif ($column === 'occurred_at') {
                $value = self::time($value);
            } elseif ($column === 'seq') {
                $value = is_numeric($value) ? (int) $value : null;
            }

            $out[$column] = $value;
        }

        return json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function time(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse(is_string($value) ? $value : '');

        return $time->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
