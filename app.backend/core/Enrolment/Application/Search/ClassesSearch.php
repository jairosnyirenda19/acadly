<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Search;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Search\SearchHit;
use Acadly\Kernel\Search\SearchQuery;
use Acadly\Kernel\Search\SearchSource;

/** Classes (12-search §6): the classes this year the reader's learner list shows, by name. */
final class ClassesSearch implements SearchSource
{
    public function __construct(private readonly VisibleClasses $classes) {}

    public function key(): string
    {
        return 'classes';
    }

    public function module(): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Classes';
    }

    public function position(): int
    {
        return 30;
    }

    public function available(User $who): bool
    {
        return $this->classes->of($who) !== [];
    }

    public function search(User $who, SearchQuery $query): array
    {
        $hits = [];

        foreach ($this->classes->of($who) as $c) {
            $folded = ' '.SearchQuery::fold($c['name']);
            $every = array_filter($query->words, fn (string $w): bool => str_contains($folded, ' '.$w)) === $query->words;

            if ($every) {
                $hits[] = new SearchHit($c['id'], 'class', $c['name'], null, $c['campus']);
            }
        }

        return array_slice($hits, $query->offset, $query->limit + 1);
    }
}
