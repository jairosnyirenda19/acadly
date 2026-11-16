<?php

declare(strict_types=1);

namespace Acadly\Kernel\Grid;

use Closure;
use Illuminate\Database\Query\Builder;

/**
 * One filter of a list. A `choice` filter offers options; the rows that match
 * any chosen option are kept, and each option says how many rows it has
 * within the other filters. A `dates` filter keeps rows whose column falls
 * between two days, either open.
 */
final readonly class GridFilter
{
    /**
     * @param  'choice'|'dates'  $kind
     * @param  list<GridOption>  $options
     * @param  literal-string|null  $column
     */
    private function __construct(
        public string $key,
        public string $label,
        public string $kind,
        public array $options,
        public ?string $column,
    ) {}

    /** @param list<GridOption> $options */
    public static function choice(string $key, string $label, array $options): self
    {
        return new self($key, $label, 'choice', $options, null);
    }

    /** @param literal-string $column the SQL of the date, written in the code */
    public static function dates(string $key, string $label, string $column): self
    {
        return new self($key, $label, 'dates', [], $column);
    }

    /** @param list<string> $values for a choice the chosen option values; for dates [from, to] */
    public function apply(Builder $query, array $values): void
    {
        if ($this->kind === 'dates') {
            [$from, $to] = [$values[0] ?? '', $values[1] ?? ''];
            $column = $this->column ?? 'null';

            if ($from !== '') {
                $query->whereRaw("({$column})::date >= ?", [$from]);
            }

            if ($to !== '') {
                $query->whereRaw("({$column})::date <= ?", [$to]);
            }

            return;
        }

        $chosen = array_values(array_filter($this->options, fn (GridOption $o): bool => in_array($o->value, $values, true)));

        if ($chosen === []) {
            return;
        }

        $query->where(function (Builder $any) use ($chosen): void {
            foreach ($chosen as $option) {
                $any->orWhere(fn (Builder $one): Builder => self::applied($option->apply, $one));
            }
        });
    }

    /** @param Closure(Builder): mixed $apply */
    private static function applied(Closure $apply, Builder $query): Builder
    {
        $apply($query);

        return $query;
    }
}
