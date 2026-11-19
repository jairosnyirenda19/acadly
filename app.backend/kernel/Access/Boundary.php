<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

use Acadly\Kernel\Http\Problem;
use Acadly\Kernel\Identity\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use WeakReference;

/**
 * The place a request works in (D-099): a boundary on top of the roles held.
 * Divisions and campuses are walls inside one school. A person who may work
 * in more than one division or campus sees one of them at a time, the one
 * they chose; what lies outside it is "not found", whatever roles they hold.
 *
 * The console names the place in the X-Acadly-Place header, as a place key
 * ('division:<id>', 'campus:<id>', 'offering:<id>', 'class:<id>', a module's
 * group such as 'office:<id>', or 'institution:' for the school view). The
 * place must be one the account may work in. Without the header the place is
 * the narrowest one that holds every role of the account; for an account
 * with roles in several divisions that is the school view, which shows no
 * record of any division.
 *
 * Inside a place, a check at the whole school (a school-wide setting) is
 * decided by the roles alone; a check anywhere else must be at the place,
 * inside it, or at the division that holds it. In the school view
 * only checks at the whole school pass, unless the school has one division
 * at one campus, and the setup permissions config names (acadly.boundary). A group place opens only its modules' permissions, at its
 * offerings.
 *
 * The boundary holds for the account that made the request, never for
 * another account being asked about (who may receive a notice), and not for
 * work without a request (the queue, the command line).
 */
final class Boundary
{
    public const string HEADER = 'X-Acadly-Place';

    /** The same, for a request that cannot carry a header (a file opened in a new tab, an image). */
    public const string COOKIE = 'acadly_place';

    /** Route names that work outside any place: the account's own context, and the parent portal. */
    private const array OPEN = ['me', 'me.context', 'kernel.', 'auth.', 'portal.'];

    private bool $resolved = false;

    /** The request the place was read from: a new request reads it again. */
    /** @var WeakReference<Request>|null */
    private ?WeakReference $request = null;

    private ?string $userId = null;

    /** @var array{type: 'none'}|array{type: 'school'}|array{type: 'place', scope: ScopeRef, around: list<string>}|array{type: 'group', key: string, offerings: list<string>, modules: list<string>} */
    private array $place = ['type' => 'none'];

    /** @var array<string, bool> */
    private array $answers = [];

    public function __construct(
        private readonly Container $container,
        private readonly ScopeHierarchy $hierarchy,
    ) {}

    /** Whether a check by this account at this scope, for this permission of this module, falls outside the place. */
    public function refuses(User $who, ScopeRef $at, string $module, string $permission = ''): bool
    {
        $this->resolve();

        if ($this->userId === null || $who->id !== $this->userId || $this->place['type'] === 'none') {
            return false;
        }

        $key = WorkPlaces::key($at).'|'.$module.'|'.$permission;

        return ! ($this->answers[$key] ??= $this->inside($at, $module, $permission));
    }

    /** The key of the place the request works in, or null when there is no boundary. */
    public function key(): ?string
    {
        $this->resolve();

        return match ($this->place['type']) {
            'none' => null,
            'school' => 'institution:',
            'place' => WorkPlaces::key($this->place['scope']),
            'group' => $this->place['key'],
        };
    }

    /** Whether the request works inside one part of a school that has several: a division, a campus, a class or a group. */
    public function insidePart(): bool
    {
        $this->resolve();

        return in_array($this->place['type'], ['place', 'group'], true) && ! $this->oneOffering();
    }

    /** Whether the request works in the school view: whole-school settings and figures, no records. */
    public function schoolView(): bool
    {
        $this->resolve();

        return $this->place['type'] === 'school';
    }

    /**
     * The offerings (a division at a campus) the request may see, or null when it is not bounded.
     *
     * @return list<string>|null
     */
    public function offerings(): ?array
    {
        $this->resolve();

        if ($this->place['type'] === 'none') {
            return null;
        }

        if ($this->place['type'] === 'school') {
            return $this->oneOffering() ? null : [];
        }

        if ($this->place['type'] === 'group') {
            return $this->place['offerings'];
        }

        $out = [];
        foreach ($this->hierarchy->places() as $p) {
            if ($p['scope']->type === ScopeType::Offering && $this->inside($p['scope'], '')) {
                $out[] = (string) $p['scope']->id;
            }
        }

        // A class: its own offering.
        if ($this->place['scope']->type === ScopeType::ClassGroup) {
            foreach ($this->hierarchy->ancestors($this->place['scope']) as $a) {
                if ($a->type === ScopeType::Offering) {
                    $out[] = (string) $a->id;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Keeps a query to the rows whose own place (a scope type and id column,
     * as a sent message keeps) is inside the place worked in. In the school
     * view, only rows kept for the whole school. Unbounded work is not limited.
     */
    public function keepToPlace(Builder $query, string $typeColumn, string $idColumn): Builder
    {
        $this->resolve();

        if ($this->place['type'] === 'none' || ($this->place['type'] === 'school' && $this->oneOffering())) {
            return $query;
        }

        $keys = $this->place['type'] === 'school' ? ['institution:'] : [];

        if ($this->place['type'] !== 'school') {
            foreach ($this->hierarchy->places() as $p) {
                if ($p['scope']->type !== ScopeType::Institution && $this->inside($p['scope'], '')) {
                    $keys[] = WorkPlaces::key($p['scope']);
                }
            }
            if ($this->place['type'] === 'place') {
                $keys[] = WorkPlaces::key($this->place['scope']);
            }
        }

        $byType = [];
        foreach (array_unique($keys) as $key) {
            [$type, $id] = explode(':', $key, 2);
            $byType[$type][] = $id;
        }

        return $query->where(function (Builder $q) use ($byType, $typeColumn, $idColumn): void {
            $q->whereRaw('1 = 0');
            foreach ($byType as $type => $ids) {
                $q->orWhere(fn (Builder $w): Builder => $w->where($typeColumn, $type)
                    ->where(fn (Builder $x): Builder => in_array('', $ids, true) ? $x->whereNull($idColumn)->orWhereIn($idColumn, array_values(array_filter($ids))) : $x->whereIn($idColumn, $ids)));
            }
        });
    }

    /**
     * The key for anything remembered about what an account may see: the
     * account and the place, so a change of place never reuses an answer.
     */
    public static function cacheKey(User $who): string
    {
        $key = app(self::class)->key();

        return $who->id.'|'.($key ?? '');
    }

    /** For tests and the queue: forget what was resolved, so the next check reads the request again. */
    public function forget(): void
    {
        $this->resolved = false;
        $this->userId = null;
        $this->place = ['type' => 'none'];
        $this->answers = [];
    }

    private function inside(ScopeRef $at, string $module, string $permission = ''): bool
    {
        $place = $this->place;

        if ($place['type'] === 'none') {
            return true;
        }

        if ($place['type'] === 'group') {
            if ($module !== '' && ! in_array($module, $place['modules'], true)) {
                return false;
            }
            if ($at->type === ScopeType::Institution) {
                return true;
            }
            // A division or a campus whose every offering the group holds: kept for the whole division (its term fees).
            if (in_array($at->type, [ScopeType::Division, ScopeType::Campus], true)) {
                $under = [];
                foreach ($this->hierarchy->places() as $p) {
                    if ($p['scope']->type === ScopeType::Offering && in_array(WorkPlaces::key($at), array_map(WorkPlaces::key(...), $this->hierarchy->ancestors($p['scope'])), true)) {
                        $under[] = (string) $p['scope']->id;
                    }
                }

                return $under !== [] && array_diff($under, $place['offerings']) === [];
            }
            $around = array_map(WorkPlaces::key(...), [$at, ...$this->hierarchy->ancestors($at)]);
            foreach ($place['offerings'] as $offering) {
                if (in_array('offering:'.$offering, $around, true)) {
                    return true;
                }
            }

            return false;
        }

        // A check for the whole school (a school-wide setting) is decided by the roles alone. Lists behind such a
        // check must still keep to the place, record by record: the boundary test proves it.
        if ($at->type === ScopeType::Institution) {
            return true;
        }

        if ($place['type'] === 'school') {
            // School-wide setup that reaches into each division (the next school year's classes) is named in config, permission by permission.
            $open = config('acadly.boundary.school_view', []);

            // A permission alone opens every scope below; 'permission@type' opens only scopes of that type.
            return $this->oneOffering() || (is_array($open) && (in_array($permission, $open, true) || in_array($permission.'@'.$at->type->value, $open, true)));
        }

        $within = array_map(WorkPlaces::key(...), [$at, ...$this->hierarchy->ancestors($at)]);

        // At the place or inside it, or at the division or campus that holds it.
        return in_array(WorkPlaces::key($place['scope']), $within, true) || in_array(WorkPlaces::key($at), $place['around'], true);
    }

    private function oneOffering(): bool
    {
        $offerings = 0;
        foreach ($this->hierarchy->places() as $p) {
            $offerings += $p['scope']->type === ScopeType::Offering ? 1 : 0;
        }

        return $offerings <= 1;
    }

    private function resolve(): void
    {
        $request = $this->container->bound('request') ? $this->container->make('request') : null;
        $current = $request instanceof Request ? $request : null;

        $now = $current?->user();
        $nowId = $now instanceof User ? $now->id : $this->userId;

        if ($this->resolved && $this->request?->get() === $current && $this->userId === $nowId) {
            return;
        }

        $this->forget();
        $this->resolved = true;
        $this->request = $current !== null ? WeakReference::create($current) : null;

        if (! $request instanceof Request) {
            return;
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return;
        }

        $name = (string) $request->route()?->getName();
        foreach (self::OPEN as $open) {
            if ($name === $open || (str_ends_with($open, '.') && str_starts_with($name, $open))) {
                return;
            }
        }

        $this->userId = $user->id;
        $places = $this->container->make(WorkPlaces::class);
        $cookie = $request->cookies->get(self::COOKIE);
        $asked = trim((string) $request->header(self::HEADER, ''));
        $asked = $asked !== '' ? $asked : (is_string($cookie) ? trim($cookie) : '');

        if ($asked === '') {
            $this->place = $this->fallback($places->of($user));

            return;
        }

        foreach ($this->container->tagged(PlaceGroups::TAG) as $groups) {
            if ($groups instanceof PlaceGroups && str_starts_with($asked, $groups->prefix().':')) {
                $id = substr($asked, strlen($groups->prefix()) + 1);
                foreach ($groups->groupsFor($user) as $g) {
                    if ($g['id'] === $id) {
                        $this->place = ['type' => 'group', 'key' => $asked, 'offerings' => $g['offerings'], 'modules' => $g['modules']];

                        return;
                    }
                }

                throw self::notHeld();
            }
        }

        foreach ($places->of($user) as $row) {
            if ($row['key'] === $asked) {
                $this->place = $row['type'] === ScopeType::Institution->value ? ['type' => 'school'] : self::placeOf($row);

                return;
            }
        }

        throw self::notHeld();
    }

    /**
     * The narrowest place that holds every role of the account.
     *
     * @param  list<array{key: string, type: string, id: string|null, label: string, within: list<string>, held: bool, roles: list<array{role: string, ends_at: string|null}>}>  $rows
     * @return array{type: 'school'}|array{type: 'place', scope: ScopeRef, around: list<string>}
     */
    private function fallback(array $rows): array
    {
        $held = array_values(array_filter($rows, fn (array $r): bool => $r['held']));

        if ($held === []) {
            return ['type' => 'school'];
        }

        $common = $held[0]['within'];
        foreach ($held as $r) {
            $common = array_values(array_intersect($common, $r['within']));
        }

        $nearest = $common[0] ?? 'institution:';
        if ($nearest === 'institution:') {
            return ['type' => 'school'];
        }

        foreach ($rows as $r) {
            if ($r['key'] === $nearest) {
                return self::placeOf($r);
            }
        }

        return ['type' => 'school'];
    }

    /**
     * @param  array{key: string, type: string, id: string|null, within: list<string>}  $row
     * @return array{type: 'place', scope: ScopeRef, around: list<string>}
     */
    private static function placeOf(array $row): array
    {
        $scope = ScopeRef::of(ScopeType::from($row['type']), $row['id']);
        // The division that holds an offering or a class is open too, for what is kept for the whole division (its programmes,
        // its subjects). Never the campus: a campus holds other divisions. Lists kept by division keep to the offerings.
        $around = array_values(array_filter($row['within'], fn (string $k): bool => str_starts_with($k, 'division:') && $k !== $row['key']));

        return ['type' => 'place', 'scope' => $scope, 'around' => $around];
    }

    private static function notHeld(): Problem
    {
        return new Problem(403, 'place_not_held', 'You do not work in this place. Choose another.');
    }
}
