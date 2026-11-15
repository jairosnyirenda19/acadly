<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

use Carbon\CarbonImmutable;

/**
 * The old and new values of one change, split by field class. Secret values
 * never enter it: only the names of the secret fields that changed.
 */
final readonly class Diff
{
    /**
     * @param  array<string, array{old: scalar|null, new: scalar|null}>  $plain
     * @param  array<string, array{old: scalar|null, new: scalar|null}>  $personal
     * @param  list<string>  $secret
     */
    public function __construct(
        public array $plain = [],
        public array $personal = [],
        public array $secret = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * Compares two snapshots by the classes the record declares.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, FieldClass>  $fields
     */
    public static function between(array $before, array $after, array $fields): self
    {
        $plain = $personal = $secret = [];

        foreach ($fields as $field => $class) {
            $old = self::scalar($before[$field] ?? null);
            $new = self::scalar($after[$field] ?? null);

            if ($old === $new) {
                continue;
            }

            match ($class) {
                FieldClass::Plain => $plain[$field] = ['old' => $old, 'new' => $new],
                FieldClass::Personal => $personal[$field] = ['old' => $old, 'new' => $new],
                FieldClass::Secret => $secret[] = $field,
            };
        }

        ksort($plain);
        ksort($personal);
        sort($secret);

        return new self($plain, $personal, $secret);
    }

    public function isEmpty(): bool
    {
        return $this->plain === [] && $this->personal === [] && $this->secret === [];
    }

    /**
     * What the line itself keeps: plain values, and secret fields as masked.
     *
     * @return array<string, array{old: scalar|null, new: scalar|null}|array{masked: true}>
     */
    public function lineChanges(): array
    {
        $out = $this->plain;

        foreach ($this->secret as $field) {
            $out[$field] = ['masked' => true];
        }

        ksort($out);

        return $out;
    }

    /** A value the trail can store and hash the same way every time. */
    private static function scalar(mixed $value): string|int|bool|null
    {
        return match (true) {
            $value === null, is_string($value), is_int($value), is_bool($value) => $value,
            is_float($value) => (string) $value,
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            $value instanceof \Stringable => (string) $value,
            default => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
