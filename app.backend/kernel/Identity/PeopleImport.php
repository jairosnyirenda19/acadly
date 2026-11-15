<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Actions\AssignRole;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\Actions\InviteAccount;
use Acadly\Kernel\Identity\Actions\SendInvitation;
use Acadly\Kernel\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Validation\CellReference;
use OpenSpout\Writer\XLSX\Validation\Rules\ListValidationRule;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

/**
 * Inviting many people from a spreadsheet (the approved import screens).
 * Every row is checked first with the same rules as inviting one person: a
 * real email address, a role that exists, a place in force, and a role whose
 * permissions the viewer holds at that place. Nothing is saved until the
 * viewer imports; a row with a problem changes nothing.
 */
final class PeopleImport
{
    public const int MAX_ROWS = 1000;

    public const array COLUMNS = ['Full name', 'Email address', 'Role', 'Where'];

    public function __construct(
        private readonly Access $access,
        private readonly PermissionCatalogue $catalogue,
        private readonly Clock $clock,
        private readonly InviteAccount $invite,
        private readonly AssignRole $assign,
        private readonly SendInvitation $send,
        private readonly Audit $audit,
    ) {}

    /**
     * @return array{rows: list<array{row: int, name: string, email: string, role: string, where: string, state: 'ready'|'second'|'existing'|'wrong', note: string}>, totals: array{rows: int, ready: int, existing: int, wrong: int, new_people: int}}
     */
    public function check(User $viewer, string $path, string $extension): array
    {
        $roles = [];

        foreach (Role::query()->inForce()->get() as $role) {
            $roles[mb_strtolower($role->name)] = $role;
        }

        $places = [];

        foreach ($this->access->places() as $place) {
            $places[mb_strtolower($place['label'])] = $place;
        }

        $out = [];
        $seen = [];
        $newEmails = [];

        foreach ($this->read($path, $extension) as $number => [$name, $email, $roleName, $where]) {
            $result = ['row' => $number, 'name' => $name, 'email' => mb_strtolower($email), 'role' => $roleName, 'where' => $where];
            $email = mb_strtolower($email);

            $valid = Validator::make(['name' => $name, 'email' => $email], [
                'name' => ['required', 'string', 'min:2', 'max:120'],
                'email' => ['required', 'email:rfc', 'max:255', 'regex:/@[^@]+\.[^@.]{2,}$/'],
            ], [
                'name.*' => 'Give the full name, from 2 to 120 characters.',
                'email.required' => 'Give an email address.',
                'email.regex' => 'The email address has no domain ending, such as .mw or .com.',
                'email.*' => 'The email address is not valid.',
            ]);

            if ($valid->fails()) {
                $out[] = self::result($result, 'wrong', (string) $valid->errors()->first());

                continue;
            }

            $role = $roles[mb_strtolower($roleName)] ?? null;
            $place = $places[mb_strtolower($where)] ?? null;

            if (! $role instanceof Role) {
                $out[] = self::result($result, 'wrong', $roleName === '' ? 'Give a role.' : "There is no role called {$roleName}.");

                continue;
            }

            if ($place === null) {
                $out[] = self::result($result, 'wrong', $where === '' ? 'Give where the role applies.' : "There is no place called {$where}.");

                continue;
            }

            $missing = $this->missing($viewer, $role, $place['scope']);

            if (! $this->access->allows($viewer, AccessPermission::AssignRoles, $place['scope'])) {
                $out[] = self::result($result, 'wrong', "You cannot give roles at {$place['label']}.");

                continue;
            }

            if ($missing !== []) {
                $out[] = self::result($result, 'wrong', "You cannot give {$role->name} at {$place['label']}: it includes ".implode(', ', $missing).', which you do not hold there.');

                continue;
            }

            $key = $email.'|'.$role->id.'|'.$place['scope']->type->value.':'.($place['scope']->id ?? '');

            if (isset($seen[$key])) {
                $out[] = self::result($result, 'wrong', "Repeats row {$seen[$key]}.");

                continue;
            }

            $seen[$key] = $number;
            $existing = User::query()->where('email', $email)->first();

            if ($existing instanceof User) {
                if ($existing->status === AccountStatus::Disabled) {
                    $out[] = self::result($result, 'wrong', 'The account with this email address is disabled.');

                    continue;
                }

                $holds = RoleAssignment::query()->activeAt($this->clock->now())->where('user_id', $existing->id)->where('role_id', $role->id)
                    ->where('scope_type', $place['scope']->type)->where('scope_id', $place['scope']->id)->exists();

                if ($holds) {
                    $out[] = self::result($result, 'wrong', "{$existing->name} already holds {$role->name} at {$place['label']}.");

                    continue;
                }

                $out[] = self::result($result, 'existing', 'Already has an account. Only the role is given.');

                continue;
            }

            if (isset($newEmails[$email])) {
                $out[] = self::result($result, 'second', 'Second role for the same person.');

                continue;
            }

            $newEmails[$email] = true;
            $out[] = self::result($result, 'ready', 'New account. Invited with this role.');
        }

        $count = fn (string ...$states): int => count(array_filter($out, fn (array $r): bool => in_array($r['state'], $states, true)));

        return [
            'rows' => $out,
            'totals' => ['rows' => count($out), 'ready' => $count('ready', 'second'), 'existing' => $count('existing'), 'wrong' => $count('wrong'), 'new_people' => $count('ready')],
        ];
    }

    /**
     * Imports the rows without a problem, in one transaction; then sends the
     * invitations, when asked, after it has committed.
     *
     * @return array{invited: int, roles: int, left_out: int}
     */
    public function import(User $viewer, string $path, string $extension, bool $send): array
    {
        $checked = $this->check($viewer, $path, $extension);
        $roles = Role::query()->inForce()->get()->keyBy(fn (Role $r): string => mb_strtolower($r->name));
        $places = [];

        foreach ($this->access->places() as $place) {
            $places[mb_strtolower($place['label'])] = $place['scope'];
        }

        /** @var list<User> $invited */
        $invited = DB::transaction(function () use ($viewer, $checked, $roles, $places): array {
            $made = [];
            $people = [];

            foreach ($checked['rows'] as $row) {
                if ($row['state'] === 'wrong') {
                    continue;
                }

                $role = $roles->get(mb_strtolower($row['role']));
                $at = $places[mb_strtolower($row['where'])] ?? null;

                if (! $role instanceof Role || $at === null) {
                    continue;
                }

                if ($row['state'] === 'ready') {
                    $person = $this->invite->handle($viewer, $row['name'], $row['email'], $role, $at);
                    $people[$row['email']] = $person;
                    $made[] = $person;
                } else {
                    $person = $people[$row['email']] ?? User::query()->where('email', $row['email'])->firstOrFail();
                    $this->assign->handle($viewer, $person, $role, $at, reason: 'Imported from a spreadsheet.');
                }
            }

            $this->audit->record(KernelAudit::PeopleImported, actor: $this->audit->actorFor($viewer),
                meta: ['invited' => count($made), 'roles' => $checked['totals']['ready'] + $checked['totals']['existing'], 'left_out' => $checked['totals']['wrong']]);

            return $made;
        });

        if ($send) {
            foreach ($invited as $person) {
                $this->send->handle($viewer, $person);
            }
        }

        return ['invited' => count($invited), 'roles' => $checked['totals']['ready'] + $checked['totals']['existing'], 'left_out' => $checked['totals']['wrong']];
    }

    /**
     * A spreadsheet with the columns in place and two lists to choose from:
     * the roles the viewer can give anywhere, and the places where they can.
     */
    public function template(User $viewer, string $path): void
    {
        $roles = Role::query()->inForce()->orderByDesc('is_system')->orderBy('name')->get()
            ->filter(function (Role $role) use ($viewer): bool {
                foreach ($this->access->places() as $place) {
                    if ($this->access->allows($viewer, AccessPermission::AssignRoles, $place['scope']) && $this->missing($viewer, $role, $place['scope']) === []) {
                        return true;
                    }
                }

                return false;
            })->map(fn (Role $role): string => $role->name)->values()->all();
        $places = array_values(array_map(fn (array $p): string => $p['label'], array_filter($this->access->places(),
            fn (array $p): bool => $this->access->allows($viewer, AccessPermission::AssignRoles, $p['scope']))));

        $options = new Options;
        $options->addValidation(2, 2, 2, self::MAX_ROWS + 1, new ListValidationRule(new CellReference(0, 2, 0, max(2, count($roles) + 1), sheetName: 'Roles')));
        $options->addValidation(3, 2, 3, self::MAX_ROWS + 1, new ListValidationRule(new CellReference(0, 2, 0, max(2, count($places) + 1), sheetName: 'Places')));
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('People');
        $writer->addRow(Row::fromValues(self::COLUMNS));
        $writer->addNewSheetAndMakeItCurrent()->setName('Roles');
        $writer->addRow(Row::fromValues(['Roles you can give']));

        foreach ($roles as $name) {
            $writer->addRow(Row::fromValues([$name]));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('Places');
        $writer->addRow(Row::fromValues(['Places where you can give them']));

        foreach ($places as $label) {
            $writer->addRow(Row::fromValues([$label]));
        }

        $writer->setCurrentSheet($writer->getSheets()[0]);
        $writer->close();
    }

    /**
     * @param  array{row: int, name: string, email: string, role: string, where: string}  $base
     * @param  'ready'|'second'|'existing'|'wrong'  $state
     * @return array{row: int, name: string, email: string, role: string, where: string, state: 'ready'|'second'|'existing'|'wrong', note: string}
     */
    private static function result(array $base, string $state, string $note): array
    {
        return [...$base, 'state' => $state, 'note' => $note];
    }

    /** @return list<string> labels of the role's permissions the viewer does not hold at the place */
    private function missing(User $viewer, Role $role, ScopeRef $at): array
    {
        $missing = [];

        foreach ($role->permissionKeys() as $key) {
            if ($this->catalogue->has($key) && ! $this->access->allows($viewer, $this->catalogue->get($key), $at)) {
                $missing[] = $this->catalogue->get($key)->label();
            }
        }

        return $missing;
    }

    /**
     * The rows of the first sheet, by spreadsheet row number, after the row of
     * column names. Columns are found by their names, or taken in order.
     *
     * @return array<int, array{string, string, string, string}>
     */
    private function read(string $path, string $extension): array
    {
        $reader = $extension === 'csv' ? new CsvReader : new XlsxReader;

        try {
            $reader->open($path);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => ['The file cannot be read. Save it as .xlsx or .csv and try again.']]);
        }

        $rows = [];
        $map = [0, 1, 2, 3];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $number => $row) {
                    $cells = array_map(fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '', $row->toArray());

                    if ($number === 1) {
                        $names = array_map(fn (string $c): string => mb_strtolower($c), $cells);

                        foreach (self::COLUMNS as $i => $column) {
                            $at = array_search(mb_strtolower($column), $names, true);
                            $map[$i] = is_int($at) ? $at : $i;
                        }

                        continue;
                    }

                    if (implode('', $cells) === '') {
                        continue;
                    }

                    if (count($rows) >= self::MAX_ROWS) {
                        throw ValidationException::withMessages(['file' => ['The file has more than '.self::MAX_ROWS.' people. Split it into smaller files.']]);
                    }

                    $rows[is_int($number) ? $number : count($rows) + 2] = [$cells[$map[0]] ?? '', $cells[$map[1]] ?? '', $cells[$map[2]] ?? '', $cells[$map[3]] ?? ''];
                }

                break;
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }
}
