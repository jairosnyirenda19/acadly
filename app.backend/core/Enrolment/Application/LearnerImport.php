<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Application\Actions\AdmitLearner;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Contracts\Relationship;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
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
 * Admitting many learners from a spreadsheet (the approved import screens).
 * Every row is checked first with the same rules as admitting one learner:
 * real details, a class of this year the viewer may admit into, at least two
 * contacts, an admission number never issued. A possible duplicate is
 * admitted only when the viewer confirms that row. Nothing is saved until
 * the viewer imports; a row with a problem changes nothing.
 */
final class LearnerImport
{
    public const int MAX_ROWS = 2000;

    public const array COLUMNS = [
        'First name', 'Other names', 'Surname', 'Sex', 'Date of birth', 'Class', 'Admission number', 'Admitted on',
        'Contact 1 name', 'Contact 1 relationship', 'Contact 1 phone', 'Contact 1 email',
        'Contact 2 name', 'Contact 2 relationship', 'Contact 2 phone', 'Contact 2 email',
        'Contact 3 name', 'Contact 3 relationship', 'Contact 3 phone', 'Contact 3 email',
    ];

    /** Words people write for a relationship, beyond the names on the list. */
    private const array RELATIONSHIP_WORDS = [
        'mum' => 'mother', 'mom' => 'mother', 'dad' => 'father', 'aunt' => 'aunt_uncle', 'uncle' => 'aunt_uncle',
        'grandmother' => 'grandparent', 'grandfather' => 'grandparent', 'brother' => 'sibling', 'sister' => 'sibling',
    ];

    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly SchoolDirectory $school,
        private readonly Duplicates $duplicates,
        private readonly Numbers $numbers,
        private readonly Contacts $contacts,
        private readonly AdmitLearner $admit,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{rows: list<array{row: int, name: string, born: string, sex: string, class: string, contacts: list<string>, number: string|null, state: 'ready'|'double'|'wrong', note: string}>, totals: array{rows: int, ready: int, double: int, wrong: int, classes: int}}
     */
    public function check(User $viewer, string $path, string $extension): array
    {
        $checked = $this->checkRows($viewer, $this->read($path, $extension));

        return ['rows' => array_map(fn (array $r): array => $r['view'], $checked), 'totals' => self::totals($checked)];
    }

    /**
     * Admits the rows without a problem, and the possible duplicates the
     * viewer confirmed, in one transaction.
     *
     * @param  list<int>  $confirmed  spreadsheet row numbers confirmed as different learners
     * @return array{admitted: int, left_out: int}
     */
    public function import(User $viewer, string $path, string $extension, array $confirmed): array
    {
        $checked = $this->checkRows($viewer, $this->read($path, $extension));

        return DB::transaction(function () use ($viewer, $checked, $confirmed): array {
            $admitted = 0;

            foreach ($checked as $row) {
                $state = $row['view']['state'];

                if ($state === 'wrong' || ($state === 'double' && ! in_array($row['view']['row'], $confirmed, true)) || $row['input'] === null) {
                    continue;
                }

                $this->admit->handle($viewer, [...$row['input'], 'different' => $state === 'double'], $row['people']);
                $admitted++;
            }

            $this->audit->record(EnrolmentAudit::LearnersImported, actor: $this->audit->actorFor($viewer),
                meta: ['admitted' => $admitted, 'left_out' => count($checked) - $admitted]);

            return ['admitted' => $admitted, 'left_out' => count($checked) - $admitted];
        });
    }

    /**
     * A spreadsheet with the columns in place and lists to choose from: the
     * classes the viewer may admit into, the relationships and the sexes.
     */
    public function template(User $viewer, string $path): void
    {
        $classes = $this->admittable($viewer);
        $relationships = array_map(fn (Relationship $r): string => $r->label(), Relationship::cases());
        $last = self::MAX_ROWS + 1;

        $options = new Options;
        $options->addValidation(3, 2, 3, $last, new ListValidationRule(new CellReference(0, 2, 0, 3, sheetName: 'Lists')));
        $options->addValidation(5, 2, 5, $last, new ListValidationRule(new CellReference(1, 2, 1, max(2, count($classes) + 1), sheetName: 'Lists')));

        foreach ([9, 13, 17] as $column) {
            $options->addValidation($column, 2, $column, $last, new ListValidationRule(new CellReference(2, 2, 2, count($relationships) + 1, sheetName: 'Lists')));
        }

        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Learners');
        $writer->addRow(Row::fromValues(self::COLUMNS));
        $writer->addNewSheetAndMakeItCurrent()->setName('Lists');
        $writer->addRow(Row::fromValues(['Sex', 'Classes you can admit into', 'Relationships']));

        for ($i = 0; $i < max(2, count($classes), count($relationships)); $i++) {
            $writer->addRow(Row::fromValues([['Female', 'Male'][$i] ?? '', $classes[$i] ?? '', $relationships[$i] ?? '']));
        }

        $writer->setCurrentSheet($writer->getSheets()[0]);
        $writer->close();
    }

    /**
     * @param  array<int, list<string>>  $rows
     * @return list<array{view: array{row: int, name: string, born: string, sex: string, class: string, contacts: list<string>, number: string|null, state: 'ready'|'double'|'wrong', note: string}, input: array{first_name: string, other_names: string|null, surname: string, sex: string, date_of_birth: string, class_id: string, admitted_on: string, admission_number: string|null}|null, people: list<array{name: string, relationship: string, phone: string, email: string|null, main: bool}>, class_id: string|null}>
     */
    private function checkRows(User $viewer, array $rows): array
    {
        $classes = $this->classes($viewer);
        $today = $this->clock->now()->toDateString();
        $year = $this->calendar->currentYear();
        $out = [];
        $seenLearner = [];
        $seenNumber = [];
        $explicit = [];

        foreach ($rows as $cells) {
            $number = strtoupper(trim($cells[6] ?? ''));

            if ($number !== '') {
                $explicit[$number] = true;
            }
        }

        $next = null;

        foreach ($rows as $n => $cells) {
            [$first, $other, $surname, $sex, $born, $class, $number, $admitted] = array_pad(array_slice($cells, 0, 8), 8, '');
            $name = trim(implode(' ', array_filter([trim($first), trim($other), trim($surname)])));
            $view = ['row' => $n, 'name' => $name, 'born' => $born, 'sex' => $sex, 'class' => $class, 'contacts' => [], 'number' => null, 'state' => 'wrong', 'note' => ''];
            $wrong = fn (string $note): array => ['view' => [...$view, 'note' => $note], 'input' => null, 'people' => [], 'class_id' => null];

            $sexValue = match (mb_strtolower(trim($sex))) {
                'female', 'f' => 'female',
                'male', 'm' => 'male',
                default => '',
            };
            $bornDay = self::day($born);
            $admittedDay = trim($admitted) === '' ? $today : self::day($admitted);

            try {
                $details = LearnerDetails::check(['first_name' => $first, 'other_names' => $other, 'surname' => $surname, 'sex' => $sexValue,
                    'date_of_birth' => $bornDay ?? '-'], $today);
            } catch (ValidationException $e) {
                $out[] = $wrong(self::first($e, $bornDay === null && trim($born) !== '' ? 'The date of birth is not a real date.' : null));

                continue;
            }

            $view['born'] = $details['date_of_birth'];
            $view['sex'] = $details['sex'];
            $match = $classes[mb_strtolower(trim($class))] ?? null;

            if (trim($class) === '') {
                $out[] = $wrong('Give the class.');

                continue;
            }

            if ($match === null) {
                $out[] = $wrong("There is no class called {$class} this year.");

                continue;
            }

            if ($match['ambiguous']) {
                $out[] = $wrong("There is more than one class called {$class}. Write it with its campus, as {$match['example']}.");

                continue;
            }

            if (! $match['can_admit']) {
                $out[] = $wrong("You cannot admit into {$match['full']}.");

                continue;
            }

            $view['class'] = $match['label'];

            if ($admittedDay === null) {
                $out[] = $wrong('The date admitted is not a real date.');

                continue;
            }

            if ($admittedDay > $today) {
                $out[] = $wrong('The date admitted cannot be in the future.');

                continue;
            }

            if ($year !== null && $admittedDay > $year['ends_on']) {
                $out[] = $wrong('The date admitted is after the end of the year.');

                continue;
            }

            $people = [];

            foreach ([8, 12, 16] as $i => $at) {
                [$cName, $cRelation, $cPhone, $cEmail] = array_pad(array_slice($cells, $at, 4), 4, '');

                if (trim($cName.$cRelation.$cPhone.$cEmail) === '') {
                    continue;
                }

                $people[] = ['name' => $cName, 'relationship' => self::relationship($cRelation), 'phone' => $cPhone, 'email' => $cEmail !== '' ? $cEmail : null, 'main' => $i === 0];
            }

            try {
                $people = $this->contacts->check($people);
            } catch (ValidationException $e) {
                $out[] = $wrong(count($people) < Contacts::MINIMUM
                    ? (count($people) === 1 ? 'Only one contact. Each learner needs at least two.' : 'No contact. Each learner needs at least two.')
                    : self::first($e, null, 'Contact '));

                continue;
            }

            $view['contacts'] = array_map(fn (array $c): string => $c['name'].', '.mb_strtolower(Relationship::from($c['relationship'])->label()), $people);
            $key = mb_strtolower($details['first_name'].'|'.$details['surname'].'|'.$details['date_of_birth']);

            if (isset($seenLearner[$key])) {
                $out[] = $wrong("Repeats row {$seenLearner[$key]}: the same names and date of birth.");

                continue;
            }

            $seenLearner[$key] = $n;
            $number = strtoupper(trim($number));

            if ($number !== '') {
                if (isset($seenNumber[$number])) {
                    $out[] = $wrong("Admission number {$number} repeats row {$seenNumber[$number]}.");

                    continue;
                }

                try {
                    $number = $this->numbers->checkOrNext($number);
                } catch (ValidationException $e) {
                    $out[] = $wrong(str_replace('This admission number', "Admission number {$number}", self::first($e)));

                    continue;
                }

                $seenNumber[$number] = $n;
            }

            $same = $this->duplicates->like($details['first_name'], $details['surname'], $details['date_of_birth']);
            $state = $same === null ? 'ready' : 'double';

            if ($number === '') {
                $next = $next === null ? $this->numbers->next() : Numbers::after($next);

                while (isset($explicit[$next]) || isset($seenNumber[$next])) {
                    $next = Numbers::after($next);
                }

                $number = $next;
            }

            $note = $same === null ? "Admitted with {$number}." : (string) ($this->duplicates->refusal($viewer, $same)['duplicate'][0] ?? '');
            $out[] = [
                'view' => [...$view, 'number' => $number, 'state' => $state, 'note' => $note],
                'input' => [...$details, 'class_id' => $match['id'], 'admitted_on' => $admittedDay, 'admission_number' => $number],
                'people' => $people,
                'class_id' => $match['id'],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{view: array{state: string}, class_id: string|null}>  $checked
     * @return array{rows: int, ready: int, double: int, wrong: int, classes: int}
     */
    private static function totals(array $checked): array
    {
        $count = fn (string $state): int => count(array_filter($checked, fn (array $r): bool => $r['view']['state'] === $state));
        $classes = array_unique(array_filter(array_column($checked, 'class_id'), is_string(...)));

        return ['rows' => count($checked), 'ready' => $count('ready'), 'double' => $count('double'), 'wrong' => $count('wrong'), 'classes' => count($classes)];
    }

    /**
     * The classes of this year by the names a spreadsheet may use: the name
     * alone, and the name with its campus.
     *
     * @return array<string, array{id: string, label: string, full: string, example: string, can_admit: bool, ambiguous: bool}>
     */
    private function classes(User $viewer): array
    {
        $year = $this->calendar->currentYear();

        if ($year === null) {
            return [];
        }

        $campuses = [];

        foreach ($this->school->campusViews() as $campus) {
            $campuses[$campus->id] = $campus->name;
        }

        $several = count($campuses) > 1;
        $out = [];

        foreach ($this->calendar->classesInYear($year['id']) as $class) {
            $full = $class['name'].' · '.($campuses[$class['campus_id']] ?? '');
            $label = $several ? $full : $class['name'];
            $entry = ['id' => $class['id'], 'label' => $label, 'full' => $full, 'example' => $full,
                'can_admit' => $this->access->allows($viewer, EnrolmentPermission::Admit, ScopeRef::of(ScopeType::ClassGroup, $class['id'])), 'ambiguous' => false];
            $out[mb_strtolower($full)] = $entry;
            $short = mb_strtolower($class['name']);

            if (isset($out[$short]) && $out[$short]['id'] !== $class['id']) {
                $out[$short]['ambiguous'] = true;
            } elseif (! isset($out[$short])) {
                $out[$short] = $entry;
            }
        }

        return $out;
    }

    /** @return list<string> the classes the viewer may admit into, each once, as the template lists them */
    private function admittable(User $viewer): array
    {
        $labels = [];

        foreach ($this->classes($viewer) as $entry) {
            if ($entry['can_admit']) {
                $labels[$entry['id']] = $entry['label'];
            }
        }

        return array_values($labels);
    }

    private static function relationship(string $value): string
    {
        $value = mb_strtolower(trim($value));

        foreach (Relationship::cases() as $r) {
            if ($value === $r->value || $value === mb_strtolower($r->label())) {
                return $r->value;
            }
        }

        return self::RELATIONSHIP_WORDS[$value] ?? $value;
    }

    /** A day written 02/05/2012 (day first), 2012-05-02, or kept by the spreadsheet as a date. */
    private static function day(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $value, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    private static function first(ValidationException $e, ?string $instead = null, string $prefix = ''): string
    {
        if ($instead !== null) {
            return $instead;
        }

        foreach ($e->errors() as $field => $messages) {
            $message = is_array($messages) && is_string($messages[0] ?? null) ? $messages[0] : '';

            if ($prefix !== '' && preg_match('/^contacts\.(\d+)\./', (string) $field, $m) === 1) {
                return $prefix.((int) $m[1] + 1).': '.lcfirst($message);
            }

            return $message;
        }

        return 'This row cannot be imported.';
    }

    /**
     * The rows of the first sheet, by spreadsheet row number, after the row of
     * column names. Columns are found by their names, or taken in order.
     *
     * @return array<int, list<string>>
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
        $map = array_keys(self::COLUMNS);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $number => $row) {
                    $cells = array_map(fn (mixed $v): string => $v instanceof DateTimeInterface ? $v->format('Y-m-d') : (is_scalar($v) ? trim((string) $v) : ''), $row->toArray());

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
                        throw ValidationException::withMessages(['file' => ['The file has more than '.self::MAX_ROWS.' learners. Split it into smaller files.']]);
                    }

                    $rows[is_int($number) ? $number : count($rows) + 2] = array_map(fn (int $at): string => $cells[$at] ?? '', $map);
                }

                break;
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }
}
