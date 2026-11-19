<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Boundary;
use Acadly\Kernel\Access\PlaceGroups;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Illuminate\Support\Facades\DB;

/**
 * The school's finance offices (D-099), as places to work in. One office for
 * the whole school unless the school splits its offerings among named offices;
 * an offering no office names stays with the school's own office ('school').
 * Inside an office only Fees (and finding a learner) is open, and only for the
 * learners of its offerings. Each office names its staff (D-100): a person
 * works in an office only when they are on its list, and money is taken for
 * an office's learners only by its staff.
 *
 * @phpstan-type Office array{id: string, name: string, offerings: list<string>, own: bool, staff: list<string>}
 */
final class FeeOffices implements PlaceGroups
{
    public const string SCHOOL = 'school';

    public const string OWN_NAME = 'Finance office';

    public function __construct(private readonly Access $access) {}

    public function prefix(): string
    {
        return 'office';
    }

    /**
     * Every office, the school's own first when it covers any offering.
     *
     * @return list<Office>
     */
    public function offices(): array
    {
        $all = self::offerings();
        $named = [];
        $taken = [];

        foreach (DB::table('fee_offices')->orderBy('position')->get(['id', 'name']) as $o) {
            $ids = DB::table('fee_office_offerings')->where('office_id', Row::text($o, 'id'))->pluck('offering_id')->map(fn ($v): string => is_string($v) ? $v : '')->all();
            $ids = array_values(array_intersect($ids, $all));
            $taken = [...$taken, ...$ids];
            $named[] = ['id' => Row::text($o, 'id'), 'name' => Row::text($o, 'name'), 'offerings' => $ids, 'own' => false, 'staff' => self::staffOf(Row::text($o, 'id'))];
        }

        $rest = array_values(array_diff($all, $taken));

        return $rest === [] && $named !== [] ? $named : [['id' => self::SCHOOL, 'name' => self::OWN_NAME, 'offerings' => $rest, 'own' => true, 'staff' => self::staffOf(self::SCHOOL)], ...$named];
    }

    public function groupsFor(User $user): array
    {
        $out = [];
        foreach ($this->offices() as $office) {
            if ($office['offerings'] !== [] && in_array($user->id, $office['staff'], true) && $this->holdsAt($user, $office['offerings'])) {
                $out[] = ['id' => $office['id'], 'label' => $office['name'], 'offerings' => $office['offerings'], 'modules' => ['fees', 'enrolment']];
            }
        }

        return $out;
    }

    public function group(string $id): ?array
    {
        foreach ($this->offices() as $office) {
            if ($office['id'] === $id) {
                return ['id' => $office['id'], 'label' => $office['name'], 'offerings' => $office['offerings'], 'modules' => ['fees', 'enrolment']];
            }
        }

        return null;
    }

    /** The office of a learner: the one that serves the offering the learner is placed in. */
    public function ofLearner(string $learnerId): string
    {
        $place = DB::table('placements')->where('learner_id', $learnerId)->whereNull('ends_on')->orderByDesc('starts_on')->first(['division_id', 'campus_id']);
        $offering = $place === null ? null : DB::table('institution_offerings')->where('division_id', Row::text($place, 'division_id'))
            ->where('campus_id', Row::text($place, 'campus_id'))->value('id');

        return is_string($offering) ? $this->ofOffering($offering) : self::SCHOOL;
    }

    public function ofOffering(string $offeringId): string
    {
        $named = DB::table('fee_office_offerings')->where('offering_id', $offeringId)->value('office_id');

        return is_string($named) ? $named : self::SCHOOL;
    }

    /**
     * The office the request works for: the office worked in, or the one office
     * that serves every offering of the place; null when the place spans offices.
     */
    public function current(): ?string
    {
        $boundary = app(Boundary::class);
        $key = $boundary->key();

        if ($key !== null && str_starts_with($key, 'office:')) {
            return substr($key, 7);
        }

        $offerings = $boundary->offerings() ?? self::offerings();
        // A division or campus with one offering lists none of its own: take them from the place itself.
        if ($offerings === [] && $key !== null && preg_match('/^(division|campus):(.+)$/', $key, $m) === 1) {
            $offerings = array_values(DB::table('institution_offerings')->where($m[1] === 'division' ? 'division_id' : 'campus_id', $m[2])->whereNull('ended_at')
                ->pluck('id')->map(fn ($v): string => is_string($v) ? $v : '')->all());
        }
        $offices = array_values(array_unique(array_map(fn (string $o): string => $this->ofOffering($o), $offerings)));

        return count($offices) === 1 ? $offices[0] : ($offerings === [] && count($this->offices()) === 1 ? self::SCHOOL : null);
    }

    /**
     * The people named to work in an office.
     *
     * @return list<string> user ids
     */
    public static function staffOf(string $officeId): array
    {
        return array_values(DB::table('fee_office_staff')->where('office_id', $officeId)->orderBy('added_at')->pluck('user_id')->map(fn ($v): string => is_string($v) ? $v : '')->all());
    }

    /** Whether a person is named to work in the office. */
    public static function works(User $user, string $officeId): bool
    {
        return DB::table('fee_office_staff')->where('office_id', $officeId)->where('user_id', $user->id)->exists();
    }

    /** @return list<string> every offering in force */
    public static function offerings(): array
    {
        return array_values(DB::table('institution_offerings')->whereNull('ended_at')->orderBy('started_at')->pluck('id')->map(fn ($v): string => is_string($v) ? $v : '')->all());
    }

    /**
     * Whether a person may be named to an office: they hold a fees role at
     * every level the office serves.
     *
     * @param  list<string>  $offerings
     */
    public function mayWork(User $user, array $offerings): bool
    {
        return $offerings !== [] && $this->holdsAt($user, $offerings);
    }

    /** @param  list<string>  $offerings */
    private function holdsAt(User $user, array $offerings): bool
    {
        foreach ($offerings as $offering) {
            $at = ScopeRef::of(ScopeType::Offering, $offering);
            $holds = false;
            foreach (FeesPermission::cases() as $p) {
                $holds = $holds || $this->access->holdsForCount($user, $p, $at);
            }
            if (! $holds) {
                return false;
            }
        }

        return true;
    }
}
