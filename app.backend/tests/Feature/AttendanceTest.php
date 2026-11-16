<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

const SCHOOL_DAY = '2026-11-13'; // a Friday in Term 1
const DAY_BEFORE = '2026-11-12';

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subMinute()]);
    app()->forgetScopedInstances();
    $this->travelTo('2026-11-15 10:00:00');
    $this->school = TwoCampusSchool::make();

    foreach (['primary', 'secondary'] as $division) {
        DB::table('academic_periods')->insert(['id' => Ids::next(), 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id($division),
            'name' => 'Term 1', 'position' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->class = $this->school->id('class_primary_a');
    $this->learners = [];

    foreach (['Chikondi Banda', 'Thoko Chisale', 'Madalitso Dzonzi'] as $name) {
        [$first, $surname] = explode(' ', $name);
        $this->learners[] = $this->actingAs($this->admin)->postJson('/api/v1/learners', [
            'first_name' => $first, 'surname' => $surname, 'sex' => 'female', 'date_of_birth' => '2019-05-02', 'class_id' => $this->class,
            'admitted_on' => '2026-09-08', 'contacts' => [['name' => 'Ruth', 'relationship' => 'mother', 'phone' => '0991 234 567', 'main' => true], ['name' => 'Peter', 'relationship' => 'father', 'phone' => '0888 410 220']],
        ])->assertCreated()->json('id');
    }

    $this->teacher = attendanceStaff(ScopeRef::of(ScopeType::ClassGroup, $this->class), [AttendancePermission::Capture], 'Mercy Banda');
    $this->office = attendanceStaff(ScopeRef::institution(), [AttendancePermission::Correct, AttendancePermission::Capture], 'Office');
});

/** @param  list<AttendancePermission>  $permissions */
function attendanceStaff(ScopeRef $at, array $permissions, string $name): User
{
    $role = Role::query()->create(['key' => 'a_'.substr(Ids::next(), -10), 'name' => $name.' role']);
    DB::table('access_role_permissions')->insert(array_map(fn (AttendancePermission $p): array => ['role_id' => $role->id, 'permission_key' => $p->value], $permissions));
    $user = User::factory()->create(['name' => $name]);
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $at->type, 'scope_id' => $at->id, 'starts_at' => now()->subMinute()]);

    return $user;
}

/** @param  list<string>  $statuses */
function marksFor(array $learners, array $statuses): array
{
    return array_map(fn (string $id, string $status): array => ['learner_id' => $id, 'status' => $status, 'note' => $status === 'absent' ? 'Sick.' : null], $learners, $statuses);
}

it('takes the register once a school day, with every learner marked', function (): void {
    $url = "/api/v1/registers/class/{$this->class}";

    $this->actingAs($this->teacher)->postJson($url, ['day' => SCHOOL_DAY, 'marks' => marksFor(array_slice($this->learners, 0, 2), ['present', 'present'])])
        ->assertUnprocessable()->assertJsonValidationErrors(['marks']);
    $this->actingAs($this->teacher)->postJson($url, ['day' => '2026-11-14', 'marks' => marksFor($this->learners, ['present', 'present', 'present'])])
        ->assertUnprocessable()->assertJsonValidationErrors(['day']);
    $this->actingAs($this->teacher)->postJson($url, ['day' => '2026-11-16', 'marks' => marksFor($this->learners, ['present', 'present', 'present'])])
        ->assertUnprocessable()->assertJsonValidationErrors(['day']);

    $this->actingAs($this->teacher)->postJson($url, ['day' => DAY_BEFORE, 'marks' => marksFor($this->learners, ['present', 'late', 'absent'])])->assertOk();
    $register = $this->actingAs($this->teacher)->postJson($url, ['day' => SCHOOL_DAY, 'marks' => marksFor($this->learners, ['present', 'present', 'absent'])])->assertOk()->json();

    expect($register['register']['submitted_by'])->toBe('Mercy Banda')
        ->and($register['can_take'])->toBeFalse()
        ->and($register['can_request'])->toBeTrue()
        ->and(collect($register['learners'])->pluck('mark.status')->all())->toBe(['present', 'present', 'absent'])
        ->and($register['learners'][2]['mark']['note'])->toBe('Sick.')
        ->and(collect($register['learners'])->pluck('history')->map(fn (array $h): ?string => $h[0])->all())->toBe(['present', 'late', 'absent']);

    // The same register again changes nothing; other marks are a correction, not a second register.
    $this->actingAs($this->teacher)->postJson($url, ['day' => SCHOOL_DAY, 'marks' => marksFor($this->learners, ['present', 'present', 'absent'])])->assertOk();
    $this->actingAs($this->teacher)->postJson($url, ['day' => SCHOOL_DAY, 'marks' => marksFor($this->learners, ['present', 'present', 'present'])])
        ->assertUnprocessable()->assertJsonValidationErrors(['register']);

    $day = $this->actingAs($this->office)->getJson('/api/v1/registers?day='.SCHOOL_DAY)->assertOk()->json();
    $row = collect($day['classes'])->firstWhere('id', $this->class);

    expect(DB::table('attendance_registers')->count())->toBe(2)
        ->and(DB::table('attendance_marks')->count())->toBe(6)
        ->and($row['counts'])->toBe(['present' => 2, 'absent' => 1, 'late' => 0, 'excused' => 0])
        ->and($row['teacher'])->toBeNull()
        ->and($row['division'])->not->toBe('')->and($row['campus'])->not->toBe('')
        ->and($day['totals']['taken'])->toBe(1)
        ->and($day['totals']['due'])->toBe(1)
        ->and($day['totals']['not_taken'])->toBe(0)
        ->and(collect($day['classes'])->firstWhere('id', $this->school->id('class_primary_b'))['due'])->toBeFalse();
});

it('corrects a mark through the journey, approved by someone else, keeping the first mark', function (): void {
    $this->actingAs($this->teacher)->postJson("/api/v1/registers/class/{$this->class}", ['day' => SCHOOL_DAY, 'marks' => marksFor($this->learners, ['present', 'present', 'absent'])])->assertOk();
    $registerId = DB::table('attendance_registers')->value('id');
    $learner = $this->learners[2];

    $this->actingAs($this->teacher)->postJson("/api/v1/registers/{$registerId}/corrections", ['learner_id' => $learner, 'to' => 'absent', 'reason' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors(['to']);
    $requested = $this->actingAs($this->teacher)->postJson("/api/v1/registers/{$registerId}/corrections", ['learner_id' => $learner, 'to' => 'present', 'reason' => 'She arrived at 09:10 with a note.'])
        ->assertOk()->json('learners.2.correction');

    expect($requested)->toMatchArray(['from' => 'absent', 'to' => 'present', 'state' => 'requested', 'requested_by' => 'Mercy Banda', 'can_decide' => false]);

    $this->actingAs($this->teacher)->postJson("/api/v1/registers/{$registerId}/corrections", ['learner_id' => $learner, 'to' => 'late', 'reason' => 'Again.'])
        ->assertUnprocessable()->assertJsonValidationErrors(['learner_id']);
    $this->actingAs($this->teacher)->postJson("/api/v1/registers/corrections/{$requested['id']}/decide", ['approve' => true])->assertForbidden();

    // The office may not approve a correction it asked for itself.
    $own = $this->actingAs($this->office)->postJson("/api/v1/registers/{$registerId}/corrections", ['learner_id' => $this->learners[0], 'to' => 'late', 'reason' => 'Came at 08:20.'])->json('learners.0.correction');
    $this->actingAs($this->office)->postJson("/api/v1/registers/corrections/{$own['id']}/decide", ['approve' => true])->assertForbidden()->assertJsonPath('code', 'another_person_required');

    $this->actingAs($this->office)->postJson("/api/v1/registers/corrections/{$requested['id']}/decide", ['approve' => false])->assertUnprocessable();
    $after = $this->actingAs($this->office)->postJson("/api/v1/registers/corrections/{$requested['id']}/decide", ['approve' => true])->assertOk()->json();

    expect($after['learners'][2]['mark'])->toMatchArray(['status' => 'present', 'corrected' => true])
        ->and($after['learners'][2]['correction']['state'])->toBe('approved')
        ->and(DB::table('attendance_marks')->where('learner_id', $learner)->orderBy('received_at')->pluck('status')->all())->toBe(['absent', 'present'])
        ->and(DB::table('journey_step_completions')->where('subject_id', $requested['id'])->pluck('outcome')->all())->toBe(['requested', 'approved']);

    expect(fn () => DB::table('attendance_marks')->where('learner_id', $learner)->update(['status' => 'late']))->toThrow(Exception::class);
});

it('shows a register only to those who take, see or correct it, and only while the module is on', function (): void {
    $elsewhere = attendanceStaff(ScopeRef::of(ScopeType::ClassGroup, $this->school->id('class_primary_b')), [AttendancePermission::Capture], 'Teacher at B');

    $this->actingAs($elsewhere)->getJson("/api/v1/registers/class/{$this->class}?day=".SCHOOL_DAY)->assertNotFound();
    $this->actingAs($elsewhere)->postJson("/api/v1/registers/class/{$this->class}", ['day' => SCHOOL_DAY, 'marks' => marksFor($this->learners, ['present', 'present', 'present'])])->assertForbidden();
    expect(collect($this->actingAs($elsewhere)->getJson('/api/v1/registers?day='.SCHOOL_DAY)->assertOk()->json('classes'))->pluck('id')->all())->toBe([$this->school->id('class_primary_b')]);

    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => false, 'reason' => 'Off.', 'switched_at' => '2030-01-01 00:00:00']);
    app()->forgetScopedInstances();

    $this->actingAs($this->teacher)->getJson('/api/v1/registers?day='.SCHOOL_DAY)->assertForbidden()->assertJsonPath('code', 'module_inactive');
});
