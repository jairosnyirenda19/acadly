<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

/**
 * The scope tree of the school, composed from the ScopeParents the domains
 * register. A place may have more than one parent (an offering sits in its
 * division and its campus); ancestors are walked breadth first, nearest first,
 * each once, and always end with the institution.
 *
 * A place that is not in force has only the institution above it: a role
 * held there grants nothing, and only a whole-school role reaches it. A scope
 * type no domain answers for behaves as if directly inside the institution.
 */
final class ScopeTree implements ScopeHierarchy
{
    /** The container tag a domain registers its ScopeParents under. */
    public const string PARENTS = 'acadly.scope-parents';

    /** @var array<string, ScopeParents> scope type => the one that answers for it */
    private array $answers = [];

    /** @var array<string, list<ScopeRef>|null> parents already read, for this request */
    private array $seen = [];

    /** @param  iterable<mixed>  $parents  what is registered under PARENTS; anything else is ignored */
    public function __construct(iterable $parents)
    {
        foreach ($parents as $source) {
            if (! $source instanceof ScopeParents) {
                continue;
            }

            foreach ($source->types() as $type) {
                $this->answers[$type->value] = $source;
            }
        }
    }

    public function ancestors(ScopeRef $scope): array
    {
        if ($scope->type === ScopeType::Institution) {
            return [];
        }

        $found = [];
        $queue = [$scope];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($this->parents($current) ?? [] as $parent) {
                $key = self::key($parent);

                if ($parent->type === ScopeType::Institution || isset($found[$key])) {
                    continue;
                }

                $found[$key] = $parent;
                $queue[] = $parent;
            }
        }

        return [...array_values($found), ScopeRef::institution()];
    }

    public function exists(ScopeRef $scope): bool
    {
        if ($scope->type === ScopeType::Institution || ! isset($this->answers[$scope->type->value])) {
            return true;
        }

        return $this->parents($scope) !== null;
    }

    /** @return list<ScopeRef>|null */
    private function parents(ScopeRef $scope): ?array
    {
        $source = $this->answers[$scope->type->value] ?? null;

        if ($source === null) {
            return [];
        }

        $key = self::key($scope);

        if (! array_key_exists($key, $this->seen)) {
            $this->seen[$key] = $source->parents($scope);
        }

        return $this->seen[$key];
    }

    private static function key(ScopeRef $scope): string
    {
        return $scope->type->value.':'.($scope->id ?? '');
    }
}
