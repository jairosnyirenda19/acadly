<?php

declare(strict_types=1);

namespace Acadly\Kernel\Search;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

/**
 * What was typed (12-search §3): trimmed, 2 to 80 characters, split into
 * words folded to lower case without apostrophes, so "ngoma" finds
 * "Ng'oma". Every word must match at the start of a word, in any order.
 */
final readonly class SearchQuery
{
    /** @param  list<string>  $words */
    private function __construct(
        public string $text,
        public array $words,
        public int $limit,
        public int $offset,
    ) {}

    public static function of(string $typed, int $limit = 5, int $offset = 0): self
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $typed));

        if (mb_strlen($text) < 2 || mb_strlen($text) > 80) {
            throw ValidationException::withMessages(['q' => ['Type 2 to 80 characters.']]);
        }

        $words = array_values(array_filter(explode(' ', self::fold($text)), fn (string $w): bool => $w !== ''));

        return new self($text, $words, max(1, min(50, $limit)), max(0, $offset));
    }

    /** Lower case, without apostrophes. */
    public static function fold(string $text): string
    {
        return mb_strtolower(str_replace(["'", '’', '‘', '`'], '', $text));
    }

    /** The text as a reference: no spaces, upper case, for an exact or prefix match on a number. */
    public function reference(): string
    {
        return mb_strtoupper(str_replace(' ', '', $this->text));
    }

    /**
     * Every word starts a word of the folded SQL expression.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @param  literal-string  $expression  SQL written in the code, never typed text
     * @return T
     */
    public function words(Builder $query, string $expression): Builder
    {
        $folded = "(' ' || lower(translate({$expression}, '''’‘`', '')))";

        foreach ($this->words as $word) {
            $query->whereRaw("{$folded} like ? escape '!'", ['% '.self::escape($word).'%']);
        }

        return $query;
    }

    /**
     * Orders a name that starts with the whole text first.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @param  literal-string  $expression  SQL written in the code, never typed text
     * @return T
     */
    public function startsFirst(Builder $query, string $expression): Builder
    {
        $query->orderByRaw("case when lower(translate({$expression}, '''’‘`', '')) like ? escape '!' then 0 else 1 end", [self::escape(self::fold($this->text)).'%']);

        return $query;
    }

    private static function escape(string $text): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
    }
}
