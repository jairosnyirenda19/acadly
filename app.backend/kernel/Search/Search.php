<?php

declare(strict_types=1);

namespace Acadly\Kernel\Search;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Search everything (12-search, D-089): asks every source the reader may
 * search, each in its own transaction with a time limit; a source that fails
 * is named, and the rest still answer. The typed text is written nowhere.
 */
final class Search
{
    /** @param  iterable<SearchSource>  $sources */
    public function __construct(
        private readonly ModuleActivation $modules,
        private readonly iterable $sources = [],
    ) {}

    /**
     * The places this reader may search, in their fixed order.
     *
     * @return list<SearchSource>
     */
    public function places(User $who): array
    {
        $out = [];

        foreach ($this->sources as $source) {
            $module = $source->module();

            // Module off: the source is never asked. No permission: the group is absent.
            if (($module === null || $this->modules->isActive($module)) && $source->available($who)) {
                $out[] = $source;
            }
        }

        usort($out, fn (SearchSource $a, SearchSource $b): int => $a->position() <=> $b->position());

        return $out;
    }

    /**
     * @return array{groups: list<array{key: string, label: string, hits: list<array{id: string, kind: string, title: string, reference: string|null, subtitle: string|null, state: string|null, parent: string|null}>, more: bool}>, failed: list<array{key: string, label: string}>}
     */
    public function run(User $who, SearchQuery $query, ?string $in = null): array
    {
        $groups = [];
        $failed = [];

        foreach ($this->places($who) as $source) {
            if ($in !== null && $source->key() !== $in) {
                continue;
            }

            try {
                $hits = DB::transaction(function () use ($source, $who, $query): array {
                    DB::statement("set local statement_timeout = '1500ms'");

                    return $source->search($who, $query);
                });
            } catch (Throwable $e) {
                // The source is named; the words typed are not logged.
                Log::warning('A search source did not answer.', ['source' => $source->key(), 'error' => $e::class]);
                $failed[] = ['key' => $source->key(), 'label' => $source->label()];

                continue;
            }

            if ($hits === []) {
                continue;
            }

            $groups[] = ['key' => $source->key(), 'label' => $source->label(),
                'hits' => array_map(fn (SearchHit $h): array => $h->toResponse(), array_slice($hits, 0, $query->limit)), 'more' => count($hits) > $query->limit];
        }

        return ['groups' => $groups, 'failed' => $failed];
    }
}
