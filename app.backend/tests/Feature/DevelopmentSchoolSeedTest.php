<?php

declare(strict_types=1);

use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Database\Seeders\DevelopmentAdministratorSeeder;
use Database\Seeders\DevelopmentSchoolSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    config(['acadly.development.admin_email' => 'seed.admin@example.test', 'acadly.development.admin_name' => 'Seed Administrator',
        'acadly.development.admin_password' => 'a-long-seed-passphrase']);
    $this->travelTo('2026-11-17 08:20:00');
    $this->artisan('acadly:sync')->assertSuccessful();
    $this->school = TwoCampusSchool::make();
    DB::table('academic_subjects')->insert(['id' => Ids::next(), 'division_id' => $this->school->id('secondary'), 'short' => 'BIO', 'name' => 'Biology',
        'core' => true, 'levels' => json_encode(['F1']), 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $this->file = tempnam(sys_get_temp_dir(), 'school').'.json';
    file_put_contents($this->file, json_encode([
        'roles' => [['name' => 'Subject teacher', 'description' => 'Teaches.', 'permissions' => ['enrolment.learners.view', 'academics.teach']]],
        'staff' => [['name' => 'Tadala Mvula', 'email' => 'tadala.mvula@example.test', 'roles' => [['role' => 'Subject teacher', 'at' => 'campus:Blantyre'], ['role' => 'Subject teacher', 'at' => 'campus:Nowhere']]]],
        'teaching' => [['class' => 'Blantyre/F1', 'subject' => 'BIO', 'teacher' => 'tadala.mvula@example.test']],
        'learners' => [
            ['class' => 'Blantyre/F1', 'first_name' => 'Alinafe', 'surname' => 'Banda', 'sex' => 'female', 'date_of_birth' => '2012-05-02',
                'contacts' => [['name' => 'Ruth Banda', 'relationship' => 'mother', 'phone' => '0991 234 567', 'main' => true], ['name' => 'Peter Banda', 'relationship' => 'father', 'phone' => '0888 410 220', 'main' => false]]],
            ['class' => 'Lilongwe/F9', 'first_name' => 'Gift', 'surname' => 'Lungu', 'sex' => 'male', 'date_of_birth' => '2012-01-01', 'contacts' => []],
        ],
    ]));
    DevelopmentSchoolSeeder::$file = $this->file;
});

afterEach(function (): void {
    DevelopmentSchoolSeeder::$file = dirname(__DIR__, 2).'/database/seeders/dev/school.json';
    @unlink($this->file);
});

it('fills the school from the file through the actions, once, skipping places it does not have', function (): void {
    $this->seed(DevelopmentAdministratorSeeder::class);
    $this->seed(DevelopmentSchoolSeeder::class);
    $this->seed(DevelopmentSchoolSeeder::class);

    $teacher = User::query()->where('email', 'tadala.mvula@example.test')->sole();
    expect($teacher->status)->toBe(AccountStatus::Active)
        ->and(DB::table('access_role_assignments')->where('user_id', $teacher->id)->count())->toBe(1)
        ->and(DB::table('academic_teaching')->where('user_id', $teacher->id)->whereNull('ended_at')->count())->toBe(1)
        ->and(DB::table('learners')->where('surname', 'Banda')->count())->toBe(1)
        ->and(DB::table('learners')->where('surname', 'Lungu')->count())->toBe(0)
        ->and(DB::table('audit_events')->where('action', 'enrolment.learner_admitted')->count())->toBeGreaterThanOrEqual(1);
});

it('reads a file that names only real permissions and valid scopes', function (): void {
    $data = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/database/seeders/dev/school.json'), true);
    $keys = array_keys(app(PermissionCatalogue::class)->all());

    foreach ($data['roles'] as $role) {
        foreach ($role['permissions'] as $key) {
            expect(in_array($key, $keys, true) || str_starts_with($key, 'assessment.'))->toBeTrue("Unknown permission {$key}");
        }
    }

    foreach ($data['staff'] as $person) {
        foreach ($person['roles'] as $given) {
            expect(preg_match('/^(school|campus:.+|division:.+|class:.+\/.+)$/', $given['at']))->toBe(1);
        }
    }

    expect(count($data['learners']))->toBeGreaterThan(100);
});

it('refuses outside local and testing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    app()->call([app(DevelopmentSchoolSeeder::class), 'run']);
})->throws(RuntimeException::class, 'The development school is seeded only in the local and testing environments.');
