<?php

declare(strict_types=1);

namespace Database\Seeders;

use Acadly\Core\Academics\Application\Actions\TeachingActions;
use Acadly\Core\Enrolment\Application\Actions\AdmitLearner;
use Acadly\Kernel\Access\Actions\AssignRole;
use Acadly\Kernel\Access\Actions\SaveRole;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\Actions\InviteAccount;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Fills the development school from `database/seeders/dev/school.json`:
 * roles, staff with their roles, who teaches what, and learners. Everything
 * goes through the same Actions as the screens, done by the development
 * administrator, so every rule and every audit row applies.
 *
 * It runs only in the local and testing environments and can run again:
 * what is already there is left as it is. A class, campus or division the
 * school does not have is skipped and named. Staff are invited accounts with
 * no password: step into them with View as.
 */
final class DevelopmentSchoolSeeder extends Seeder
{
    /** The file to read; a test may point it elsewhere. */
    public static string $file = __DIR__.'/dev/school.json';

    /** @var array<string, int> */
    private array $counts = ['roles' => 0, 'staff' => 0, 'roles_given' => 0, 'teaching' => 0, 'learners' => 0];

    /** @var list<string> */
    private array $skipped = [];

    public function run(
        Clock $clock,
        PermissionCatalogue $catalogue,
        SaveRole $saveRole,
        InviteAccount $invite,
        AssignRole $assign,
        TeachingActions $teaching,
        AdmitLearner $admit,
    ): void {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The development school is seeded only in the local and testing environments.');
        }

        $email = mb_strtolower(trim(is_string($e = config('acadly.development.admin_email')) ? $e : ''));
        $by = User::query()->where('email', $email)->first();

        if ($by === null) {
            throw new RuntimeException('Seed the development administrator first (DevelopmentAdministratorSeeder).');
        }

        $data = json_decode((string) file_get_contents(self::$file), true, 512, JSON_THROW_ON_ERROR);
        $data = is_array($data) ? $data : [];

        foreach (self::list($data, 'roles') as $r) {
            $this->role($by, $catalogue, $saveRole, $r);
        }

        foreach (self::list($data, 'staff') as $s) {
            $this->staff($by, $clock, $invite, $assign, $s);
        }

        foreach (self::list($data, 'teaching') as $t) {
            $this->teaching($by, $teaching, $t);
        }

        foreach (self::list($data, 'learners') as $l) {
            $this->learner($by, $admit, $l);
        }

        $c = $this->counts;
        $this->command?->getOutput()->writeln("  Development school: {$c['roles']} roles, {$c['staff']} staff, {$c['roles_given']} roles given, {$c['teaching']} teaching, {$c['learners']} learners added.");

        foreach (array_unique($this->skipped) as $line) {
            $this->command?->getOutput()->writeln("  Skipped: {$line}");
        }
    }

    /** @param array<array-key, mixed> $r */
    private function role(User $by, PermissionCatalogue $catalogue, SaveRole $save, array $r): void
    {
        $name = self::text($r, 'name');

        if (Role::query()->inForce()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            return;
        }

        $permissions = array_values(array_filter(
            array_filter(is_array($r['permissions'] ?? null) ? $r['permissions'] : [], 'is_string'),
            fn (string $key): bool => $catalogue->has($key) || ! $this->skip("the permission {$key} (its module is off)"),
        ));

        $save->handle($by, null, $name, self::text($r, 'description') ?: null, $permissions);
        $this->counts['roles']++;
    }

    /** @param array<array-key, mixed> $s */
    private function staff(User $by, Clock $clock, InviteAccount $invite, AssignRole $assign, array $s): void
    {
        $email = mb_strtolower(self::text($s, 'email'));
        $user = User::query()->where('email', $email)->first();

        foreach (self::list($s, 'roles') as $given) {
            $role = Role::query()->inForce()->whereRaw('lower(name) = ?', [mb_strtolower(self::text($given, 'role'))])->first();
            $at = $this->scope(self::text($given, 'at'));

            if ($role === null || $at === null) {
                $role === null && $this->skip('the role '.self::text($given, 'role'));

                continue;
            }

            if ($user === null) {
                $user = $invite->handle($by, self::text($s, 'name'), $email, $role, $at);
                $this->counts['staff']++;
                $this->counts['roles_given']++;
            }

            // Active, so they can be given teaching; the invitation's random password stays, so only View as reaches them.
            if ($user->status === AccountStatus::Invited) {
                $user->forceFill(['status' => AccountStatus::Active])->save();
            }

            $held = RoleAssignment::query()->activeAt($clock->now())->where('user_id', $user->id)->where('role_id', $role->id)
                ->where('scope_type', $at->type)->where(fn (EloquentBuilder $q): EloquentBuilder => $at->id === null ? $q->whereNull('scope_id') : $q->where('scope_id', $at->id))->exists();

            if (! $held) {
                $assign->handle($by, $user, $role, $at, reason: 'Development school.');
                $this->counts['roles_given']++;
            }
        }
    }

    /** @param array<array-key, mixed> $t */
    private function teaching(User $by, TeachingActions $teaching, array $t): void
    {
        $class = $this->classOf(self::text($t, 'class'));
        $teacher = User::query()->where('email', mb_strtolower(self::text($t, 'teacher')))->value('id');
        $short = is_string($t['subject'] ?? null) ? $t['subject'] : null;

        if ($class === null || ! is_string($teacher)) {
            return;
        }

        $subject = null;

        if ($short !== null) {
            $subject = DB::table('academic_subjects')->where('division_id', $class['division_id'])->where('short', $short)->whereNull('retired_at')->value('id');

            if (! is_string($subject)) {
                $this->skip("the subject {$short} in ".self::text($t, 'class'));

                return;
            }
        }

        $before = DB::table('academic_teaching')->where('class_id', $class['id'])->whereNull('ended_at')
            ->where(fn (Builder $q): Builder => $subject === null ? $q->whereNull('subject_id') : $q->where('subject_id', $subject))->value('user_id');

        if ($before !== null) {
            return; // Somebody already teaches it: the school's choice stands.
        }

        try {
            $teaching->give($by, $class['id'], $subject, $teacher);
            $this->counts['teaching']++;
        } catch (ValidationException $e) {
            $this->skip(self::text($t, 'class').' '.($short ?? 'class').': '.$e->getMessage());
        }
    }

    /** @param array<array-key, mixed> $l */
    private function learner(User $by, AdmitLearner $admit, array $l): void
    {
        $class = $this->classOf(self::text($l, 'class'));

        if ($class === null) {
            return;
        }

        $first = self::text($l, 'first_name');
        $surname = self::text($l, 'surname');
        $born = self::text($l, 'date_of_birth');

        if (DB::table('learners')->whereRaw('lower(first_name) = ? and lower(surname) = ?', [mb_strtolower($first), mb_strtolower($surname)])->where('date_of_birth', $born)->exists()) {
            return;
        }

        $contacts = array_map(fn (array $c): array => [
            'name' => self::text($c, 'name'), 'relationship' => self::text($c, 'relationship'), 'phone' => self::text($c, 'phone'),
            'email' => null, 'main' => ($c['main'] ?? false) === true,
        ], self::list($l, 'contacts'));

        $admit->handle($by, [
            'first_name' => $first, 'other_names' => null, 'surname' => $surname, 'sex' => self::text($l, 'sex'), 'date_of_birth' => $born,
            'class_id' => $class['id'], 'admitted_on' => $class['starts_on'], 'admission_number' => null, 'different' => false,
        ], $contacts);
        $this->counts['learners']++;
    }

    /** "school", "campus:Name", "division:Name" or "class:Campus/Class". */
    private function scope(string $at): ?ScopeRef
    {
        if ($at === 'school') {
            return ScopeRef::institution();
        }

        [$kind, $name] = array_pad(explode(':', $at, 2), 2, '');
        $id = match ($kind) {
            'campus' => DB::table('institution_campuses')->whereNull('ended_at')->where('name', $name)->value('id'),
            'division' => DB::table('institution_divisions')->whereNull('ended_at')->where('name', $name)->value('id'),
            'class' => $this->classOf($name)['id'] ?? null,
            default => null,
        };

        if (! is_string($id)) {
            $this->skip("the place {$at}");

            return null;
        }

        return ScopeRef::of(match ($kind) {
            'campus' => ScopeType::Campus,
            'division' => ScopeType::Division,
            default => ScopeType::ClassGroup,
        }, $id);
    }

    /**
     * A class of the latest academic year, by "Campus/Class".
     *
     * @return array{id: string, division_id: string, starts_on: string}|null
     */
    private function classOf(string $name): ?array
    {
        [$campus, $class] = array_pad(explode('/', $name, 2), 2, '');
        $row = DB::table('academic_classes as c')
            ->join('institution_campuses as k', 'k.id', '=', 'c.campus_id')
            ->join('academic_years as y', 'y.id', '=', 'c.year_id')
            ->whereNull('c.ended_at')->where('k.name', $campus)->where('c.name', $class)
            ->orderByDesc('y.starts_on')
            ->first(['c.id', 'c.division_id', 'y.starts_on']);

        if ($row === null) {
            $this->skip("the class {$name}");

            return null;
        }

        return ['id' => Row::text($row, 'id'), 'division_id' => Row::text($row, 'division_id'), 'starts_on' => substr(Row::text($row, 'starts_on'), 0, 10)];
    }

    /** Notes what was skipped; always false, so it can sit in a filter. */
    private function skip(string $what): bool
    {
        $this->skipped[] = $what;

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $from
     * @return list<array<array-key, mixed>>
     */
    private static function list(array $from, string $key): array
    {
        $items = $from[$key] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /** @param array<array-key, mixed> $from */
    private static function text(array $from, string $key): string
    {
        $value = $from[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
