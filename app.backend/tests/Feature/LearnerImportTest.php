<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Application\LearnerImport;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    app()->forgetScopedInstances();
    $this->school = TwoCampusSchool::make();
    $role = Role::query()->create(['key' => 'admissions_a', 'name' => 'Admissions office']);
    DB::table('access_role_permissions')->insert(array_map(fn (EnrolmentPermission $p): array => ['role_id' => $role->id, 'permission_key' => $p->value], EnrolmentPermission::cases()));
    $this->office = User::factory()->create(['name' => 'Office at A']);
    RoleAssignment::query()->create(['user_id' => $this->office->id, 'role_id' => $role->id, 'scope_type' => ScopeType::Campus,
        'scope_id' => $this->school->id('campus_a'), 'starts_at' => now()->subMinute()]);
});

/** @param  list<list<string>>  $rows */
function learnerSheet(array $rows): UploadedFile
{
    $lines = [implode(',', LearnerImport::COLUMNS)];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn (string $c): string => '"'.str_replace('"', '""', $c).'"', array_pad($row, count(LearnerImport::COLUMNS), '')));
    }

    return UploadedFile::fake()->createWithContent('learners.csv', implode("\n", $lines)."\n");
}

/** @return list<string> */
function learnerRow(string $first, string $surname, string $born, string $class, string $number = '', bool $twoContacts = true): array
{
    return [$first, '', $surname, 'F', $born, $class, $number, '08/09/2026',
        "{$first}'s mother", 'Mother', '0884 771 900', '',
        ...($twoContacts ? ["{$first}'s uncle", 'uncle', '0999 120 334', ''] : ['', '', '', ''])];
}

it('checks every row before anything is saved, then admits the ready rows and the confirmed duplicates', function (): void {
    // An existing learner at campus A, for the duplicate row.
    $this->actingAs($this->office)->postJson('/api/v1/learners', [
        'first_name' => 'Kumbukani', 'surname' => 'Jere', 'sex' => 'male', 'date_of_birth' => '2019-05-02',
        'class_id' => $this->school->id('class_primary_a'), 'admitted_on' => '2026-09-08', 'admission_number' => 'SA-0100',
        'contacts' => [['name' => 'Alice Jere', 'relationship' => 'mother', 'phone' => '0884 771 900', 'main' => true], ['name' => 'Daniel Jere', 'relationship' => 'father', 'phone' => '0999 120 334']],
    ])->assertCreated();

    $file = learnerSheet([
        learnerRow('Chikondi', 'Banda', '14/03/2019', 'STD1 · Area 47'),                   // 2 ready
        learnerRow('Thoko', 'Chisale', '2019-06-03', 'std1 · area 47'),                    // 3 ready
        ['Kumbukani', '', 'Jere', 'M', '02/05/2019', 'STD1 · Area 47', '', '', 'Alice Jere', 'Mother', '0884 771 900', '', 'Daniel Jere', 'Father', '0999 120 334', ''], // 4 double
        learnerRow('Madalitso', 'Dzonzi', '09/01/2019', 'STD1 · Area 47', twoContacts: false), // 5 one contact
        learnerRow('Wezi', 'Kalua', '31/02/2019', 'STD1 · Area 47'),                       // 6 not a date
        learnerRow('Limbani', 'Gama', '22/08/2019', 'Form 5'),                             // 7 no such class
        learnerRow('Yamikani', 'Mbewe', '05/11/2019', 'STD1 · Blantyre'),                  // 8 outside reach
        learnerRow('Pemphero', 'Nkhoma', '17/07/2019', 'STD1'),                            // 9 ambiguous
        learnerRow('Chikondi', 'Banda', '14/03/2019', 'STD1 · Area 47'),                   // 10 repeats row 2
        learnerRow('Wongani', 'Phiri', '01/01/2019', 'STD1 · Area 47', 'SA-0100'),         // 11 number taken
    ]);

    $checked = $this->actingAs($this->office)->post('/api/v1/learners/import/check', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json();
    $byRow = collect($checked['rows'])->keyBy('row');

    expect($checked['totals'])->toMatchArray(['rows' => 10, 'ready' => 2, 'double' => 1, 'wrong' => 7])
        ->and($byRow[2])->toMatchArray(['state' => 'ready', 'number' => 'SA-0101', 'born' => '2019-03-14', 'sex' => 'female'])
        ->and($byRow[3]['number'])->toBe('SA-0102')
        ->and($byRow[3]['contacts'])->toBe(["Thoko's mother, mother", "Thoko's uncle, aunt or uncle"])
        ->and($byRow[4]['state'])->toBe('double')
        ->and($byRow[5]['note'])->toBe('Only one contact. Each learner needs at least two.')
        ->and($byRow[6]['note'])->toBe('The date of birth is not a real date.')
        ->and($byRow[7]['note'])->toBe('There is no class called Form 5 this year.')
        ->and($byRow[8]['note'])->toContain('You cannot admit into')
        ->and($byRow[9]['note'])->toContain('more than one class called STD1')
        ->and($byRow[10]['note'])->toContain('Repeats row 2')
        ->and($byRow[11]['note'])->toContain('already been issued')
        ->and(DB::table('learners')->count())->toBe(1);

    $done = $this->actingAs($this->office)->post('/api/v1/learners/import', ['file' => $file, 'confirmed' => ['4']], ['Accept' => 'application/json'])->assertOk()->json();

    expect($done)->toBe(['admitted' => 3, 'left_out' => 7])
        ->and(DB::table('learners')->count())->toBe(4)
        ->and(DB::table('learner_identifiers')->orderBy('value')->pluck('value')->all())->toBe(['SA-0100', 'SA-0101', 'SA-0102', 'SA-0103'])
        ->and(DB::table('learner_contacts')->count())->toBe(8)
        ->and(DB::table('audit_events')->where('action', 'enrolment.learners_imported')->exists())->toBeTrue();
});

it('leaves out an unconfirmed duplicate', function (): void {
    $file = learnerSheet([learnerRow('Chikondi', 'Banda', '14/03/2019', 'STD1 · Area 47')]);
    $this->actingAs($this->office)->post('/api/v1/learners/import', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->assertJson(['admitted' => 1]);

    $again = learnerSheet([learnerRow('Chikondi', 'Banda', '14/03/2019', 'STD1 · Area 47')]);
    $this->actingAs($this->office)->post('/api/v1/learners/import', ['file' => $again], ['Accept' => 'application/json'])->assertOk()->assertJson(['admitted' => 0, 'left_out' => 1]);

    expect(DB::table('learners')->count())->toBe(1);
});

it('gives a template listing only the classes the viewer may admit into', function (): void {
    $response = $this->actingAs($this->office)->get('/api/v1/learners/import/template')->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    file_put_contents($path, $response->streamedContent() !== '' ? $response->streamedContent() : (string) file_get_contents($response->baseResponse->getFile()->getPathname()));
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }
    $reader->close();

    expect($sheets['Learners'][0])->toBe(LearnerImport::COLUMNS)
        ->and(array_filter(array_column(array_slice($sheets['Lists'], 1), 1)))->toBe(['STD1 · Area 47']);
});

it('refuses the import to someone who may not admit anywhere', function (): void {
    $nobody = User::factory()->create();
    $this->actingAs($nobody)->post('/api/v1/learners/import/check', ['file' => learnerSheet([])], ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($nobody)->get('/api/v1/learners/import/template', ['Accept' => 'application/json'])->assertForbidden();
});
