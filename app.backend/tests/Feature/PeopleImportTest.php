<?php

declare(strict_types=1);

use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\InvitationMail;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()]);
    app()->forgetScopedInstances();
    Notification::fake();
    $this->school = TwoCampusSchool::make();
    $this->teacher = Role::query()->create(['key' => 'imp_teacher', 'name' => 'Class teacher']);
    DB::table('access_role_permissions')->insert(['role_id' => $this->teacher->id, 'permission_key' => AttendancePermission::Capture->value]);
    $this->head = Role::query()->create(['key' => 'imp_head', 'name' => 'Head teacher']);
    DB::table('access_role_permissions')->insert([
        ['role_id' => $this->head->id, 'permission_key' => AttendancePermission::Capture->value],
        ['role_id' => $this->head->id, 'permission_key' => AttendancePermission::Correct->value],
    ]);
    $office = Role::query()->create(['key' => 'imp_office', 'name' => 'Campus office']);
    DB::table('access_role_permissions')->insert([
        ['role_id' => $office->id, 'permission_key' => AccessPermission::AssignRoles->value],
        ['role_id' => $office->id, 'permission_key' => AttendancePermission::Capture->value],
    ]);
    $this->office = User::factory()->create();
    RoleAssignment::query()->create(['user_id' => $this->office->id, 'role_id' => $office->id, 'scope_type' => ScopeType::Campus,
        'scope_id' => $this->school->id('campus_a'), 'starts_at' => now()->subMinute()]);
});

/** @param  list<list<string>>  $rows */
function sheet(array $rows): UploadedFile
{
    $lines = array_map(fn (array $r): string => implode(',', array_map(fn (string $c): string => '"'.str_replace('"', '""', $c).'"', $r)), $rows);

    return UploadedFile::fake()->createWithContent('staff.csv', implode("\n", $lines)."\n");
}

function staffSheet(): UploadedFile
{
    User::factory()->create(['name' => 'Kondwani Chirwa', 'email' => 'k.chirwa@example.mw']);

    return sheet([
        ['Full name', 'Email address', 'Role', 'Where'],
        ['Mercy Banda', 'm.banda@example.mw', 'Class teacher', 'STD1 · Area 47'],
        ['Mercy Banda', 'm.banda@example.mw', 'class teacher', 'Area 47'],
        ['Kondwani Chirwa', 'k.chirwa@example.mw', 'Class teacher', 'Area 47'],
        ['Tadala Mvula', 't.mvula@example', 'Class teacher', 'Area 47'],
        ['Yamikani Zulu', 'y.zulu@example.mw', 'Head teacher', 'Area 47'],
        ['Chikondi Mwale', 'c.mwale@example.mw', 'Bursar', 'Area 47'],
        ['Lusungu Gondwe', 'l.gondwe@example.mw', 'Class teacher', 'Form 5'],
        ['Joseph Nyirenda', 'j.nyirenda@example.mw', 'Class teacher', 'Blantyre'],
        ['Mercy Banda', 'm.banda@example.mw', 'Class teacher', 'STD1 · Area 47'],
        ['', '', '', ''],
    ]);
}

it('checks every row with the rules of inviting one person, and saves nothing', function (): void {
    $checked = $this->actingAs($this->office)->post('/api/v1/people/import/check', ['file' => staffSheet()], ['Accept' => 'application/json'])
        ->assertOk()->json();
    $rows = collect($checked['rows'])->keyBy('row');

    expect($checked['totals'])->toBe(['rows' => 9, 'ready' => 2, 'existing' => 1, 'wrong' => 6, 'new_people' => 1])
        ->and($rows[2]['state'])->toBe('ready')
        ->and($rows[3]['state'])->toBe('second')
        ->and($rows[4]['state'])->toBe('existing')
        ->and($rows[5]['note'])->toBe('The email address has no domain ending, such as .mw or .com.')
        ->and($rows[6]['note'])->toBe('You cannot give Head teacher at Area 47: it includes Approve a register correction, which you do not hold there.')
        ->and($rows[7]['note'])->toBe('There is no role called Bursar.')
        ->and($rows[8]['note'])->toBe('There is no place called Form 5.')
        ->and($rows[9]['note'])->toBe('You cannot give roles at Blantyre.')
        ->and($rows[10]['note'])->toBe('Repeats row 2.')
        ->and(User::query()->where('email', 'm.banda@example.mw')->exists())->toBeFalse();
});

it('imports the rows without a problem in one step, then sends the invitations', function (): void {
    $done = $this->actingAs($this->office)->post('/api/v1/people/import', ['file' => staffSheet(), 'send' => '1'], ['Accept' => 'application/json'])
        ->assertOk()->json();
    $mercy = User::query()->where('email', 'm.banda@example.mw')->sole();

    expect($done)->toBe(['invited' => 1, 'roles' => 3, 'left_out' => 6])
        ->and($mercy->status)->toBe(AccountStatus::Invited)
        ->and(RoleAssignment::query()->where('user_id', $mercy->id)->count())->toBe(2)
        ->and(RoleAssignment::query()->where('user_id', User::query()->where('email', 'k.chirwa@example.mw')->value('id'))->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'identity.people_imported')->exists())->toBeTrue();
    Notification::assertSentTo($mercy, InvitationMail::class);
});

it('offers a template with only the roles and places the viewer can give', function (): void {
    $response = $this->actingAs($this->office)->get('/api/v1/people/import/template')->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    file_put_contents($path, $response->streamedContent() ?: (string) file_get_contents($response->baseResponse->getFile()->getPathname()));
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray()[0] ?? null;
        }
    }

    $reader->close();

    expect($sheets['People'][0])->toBe('Full name')
        ->and($sheets['Roles'])->toContain('Class teacher')->not->toContain('Head teacher')
        ->and($sheets['Places'])->toContain('Area 47', 'STD1 · Area 47')->not->toContain('Blantyre', 'Whole school');
});

it('refuses a file that is not a spreadsheet', function (): void {
    $this->actingAs($this->office)->post('/api/v1/people/import/check', ['file' => UploadedFile::fake()->create('notes.pdf', 10)], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['file']);
});
