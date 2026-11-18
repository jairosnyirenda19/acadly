<?php

declare(strict_types=1);

namespace Acadly\Kernel\Search\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Search\Search;
use Acadly\Kernel\Search\SearchQuery;
use Acadly\Kernel\Search\SearchSource;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Search everything (12-search, D-089). */
final class SearchController
{
    /**
     * Search.
     *
     * `?q=` 2 to 80 characters, `in=` one group, `limit=` hits per group
     * (5 by default), `offset=` for the next page of one group. Only the
     * places this reader may search answer; a closed place is absent.
     *
     * @response array{q: string, groups: list<array{key: string, label: string, hits: list<array{id: string, kind: string, title: string, reference: string|null, subtitle: string|null, state: string|null, parent: string|null}>, more: bool}>, failed: list<array{key: string, label: string}>}
     */
    public function search(Request $request, Search $search): JsonResponse
    {
        $query = SearchQuery::of($request->string('q')->toString(), $request->integer('limit', 5), $request->integer('offset'));
        $in = $request->string('in')->toString();

        return response()->json(['q' => $query->text, ...$search->run(self::user($request), $query, $in !== '' ? $in : null)]);
    }

    /**
     * The places this reader may search.
     *
     * @response array{places: list<array{key: string, label: string}>}
     */
    public function places(Request $request, Search $search): JsonResponse
    {
        return response()->json(['places' => array_map(fn (SearchSource $s): array => ['key' => $s->key(), 'label' => $s->label()], $search->places(self::user($request)))]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
