<?php

declare(strict_types=1);

use Acadly\Kernel\Access\AccessPermission;
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

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subMinute()]);
    app()->forgetScopedInstances();
    $this->travelTo('2026-11-15 10:00:00');
    $this->school = TwoCampusSchool::make();
    DB::table('academic_periods')->insert(['id' => Ids::next(), 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id('primary'),
        'name' => 'Term 1', 'position' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->class = $this->school->id('class_primary_a');
    $at = ScopeRef::of(ScopeType::ClassGroup, $this->class);

    // The class teacher takes the register and may decide corrections in the class; a second teacher may take registers there.
    $this->teacher = approvalStaff($at, [AttendancePermission::Capture, AttendancePermission::Correct], 'Mercy Banda', 'Class teacher');
    $this->other = approvalStaff($at, [AttendancePermission::Capture], 'Lusungu Phiri', 'Subject teacher');
    [$this->head, $this->headRole] = [approvalStaff(ScopeRef::institution(), [AttendancePermission::Correct], 'Agnes Chilembwe', 'Head teacher'), Role::query()->where('name', 'Head teacher')->value('id')];
    DB::table('academic_teaching')->insert(['id' => Ids::next(), 'class_id' => $this->class, 'subject_id' => null, 'user_id' => $this->teacher->id,
        'given_by' => $this->admin->id, 'given_at' => now()->subMonth()]);

    $this->learners = [];

    foreach (['Chikondi Banda', 'Thoko Chisale'] as $name) {
        [$first, $surname] = explode(' ', $name);
        $this->learners[] = $this->actingAs($this->admin)->postJson('/api/v1/learners', [
            'first_name' => $first, 'surname' => $surname, 'sex' => 'female', 'date_of_birth' => '2019-05-02', 'class_id' => $this->class, 'admitted_on' => '2026-09-08',
            'contacts' => [['name' => 'Ruth', 'relationship' => 'mother', 'phone' => '0991 234 567', 'main' => true], ['name' => 'Peter', 'relationship' => 'father', 'phone' => '0888 410 220']],
        ])->assertCreated()->json('id');
    }

    $this->actingAs($this->teacher)->postJson("/api/v1/registers/class/{$this->class}", ['day' => '2026-11-13', 'marks' => [
        ['learner_id' => $this->learners[0], 'status' => 'absent', 'note' => null], ['learner_id' => $this->learners[1], 'status' => 'present', 'note' => null]]])->assertOk();
    $this->register = (string) DB::table('attendance_registers')->value('id');

    // The school's chain: the class teacher, then the Head teacher only when absent becomes present.
    $this->actingAs($this->admin)->putJson('/api/v1/approvals/topics/attendance.correction', ['steps' => [
        ['kind' => 'relation', 'target' => 'class_teacher', 'needs' => 1, 'when' => null],
        ['kind' => 'role', 'target' => $this->headRole, 'needs' => 1, 'when' => 'to_present'],
    ]])->assertOk()->assertJsonPath('labels', ['The class teacher', 'Head teacher']);
});

/** @param  list<AttendancePermission|AccessPermission>  $permissions */
function approvalStaff(ScopeRef $at, array $permissions, string $name, string $role): User
{
    $r = Role::query()->where('name', $role)->first() ?? Role::query()->create(['key' => 'r_'.substr(Ids::next(), -10), 'name' => $role]);
    DB::table('access_role_permissions')->insertOrIgnore(array_map(fn ($p): array => ['role_id' => $r->id, 'permission_key' => $p->value], $permissions));
    $user = User::factory()->create(['name' => $name]);
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $r->id, 'scope_type' => $at->type, 'scope_id' => $at->id, 'starts_at' => now()->subMinute()]);

    return $user;
}

function askCorrection(User $by, string $register, string $learner, string $to): string
{
    test()->actingAs($by)->postJson("/api/v1/registers/{$register}/corrections", ['learner_id' => $learner, 'to' => $to, 'reason' => 'Marked in error.'])->assertOk();
    $correction = DB::table('attendance_corrections')->where('learner_id', $learner)->where('state', 'requested')->value('id');

    return (string) DB::table('approval_requests')->where('subject_id', $correction)->value('id');
}

it('asks each step in order, only the people it names, and settles the correction at the last yes', function (): void {
    $request = askCorrection($this->other, $this->register, $this->learners[0], 'present');

    expect(array_column($this->actingAs($this->teacher)->getJson('/api/v1/approvals/waiting')->assertOk()->json('waiting'), 'id'))->toBe([$request])
        ->and($this->actingAs($this->head)->getJson('/api/v1/approvals/waiting')->json('waiting'))->toBe([]);

    // The Head teacher is not asked yet; the person who asked never is.
    $this->actingAs($this->head)->postJson("/api/v1/approvals/{$request}/answer", ['approve' => true])->assertForbidden();
    $this->actingAs($this->other)->postJson("/api/v1/approvals/{$request}/answer", ['approve' => true])->assertForbidden()->assertJsonPath('code', 'another_person_required');

    $after = $this->actingAs($this->teacher)->postJson("/api/v1/approvals/{$request}/answer", ['approve' => true])->assertOk();
    expect(array_column($after->json('steps'), 'state'))->toBe(['approved', 'waiting'])
        ->and($after->json('steps.1.asked'))->toBe(['Agnes Chilembwe']);

    $done = $this->actingAs($this->head)->postJson("/api/v1/approvals/{$request}/answer", ['approve' => true])->assertOk();
    expect($done->json('state'))->toBe('approved')
        ->and(DB::table('attendance_corrections')->value('state'))->toBe('approved');
    $register = $this->actingAs($this->teacher)->getJson("/api/v1/registers/class/{$this->class}?day=2026-11-13")->assertOk();
    expect($register->json('learners.0.mark.status'))->toBe('present');
});

it('skips a step whose condition does not hold, and sends a step that would ask the requester elsewhere, saying why', function (): void {
    // Present to absent: the Head teacher's step applies only when absent becomes present.
    $request = askCorrection($this->other, $this->register, $this->learners[1], 'absent');
    $this->actingAs($this->teacher)->postJson("/api/v1/approvals/{$request}/answer", ['approve' => true])->assertOk()
        ->assertJsonPath('state', 'approved')->assertJsonPath('steps.1.state', 'skipped')
        ->assertJsonPath('steps.1.note', 'Only when the change is from absent to present or late: it does not.');

    // The class teacher asks: their own step goes to anyone else who may decide.
    $second = askCorrection($this->teacher, $this->register, $this->learners[0], 'late');
    $shown = $this->actingAs($this->admin)->getJson("/api/v1/approvals/{$second}")->assertOk();
    expect($shown->json('steps.0.asked'))->toEqualCanonicalizing(['Agnes Chilembwe', 'Grace Phiri'])
        ->and($shown->json('steps.0.note'))->toStartWith('The person who asked would have decided this step');
});

it('ends at the first no, which needs a reason, and declines the correction', function (): void {
    $request = askCorrection($this->other, $this->register, $this->learners[0], 'present');
    $this->actingAs($this->teacher)->postJson("/api/v1/approvals/{$request}/answer", ['approve' => false])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->actingAs($this->teacher)->postJson("/api/v1/approvals/{$request}/answer", ['approve' => false, 'reason' => 'She was absent; I saw the register.'])
        ->assertOk()->assertJsonPath('state', 'declined')->assertJsonPath('steps.1.state', 'pending');

    expect(DB::table('attendance_corrections')->value('state'))->toBe('declined');
});

it('keeps the catalogue to those who decide who approves, and puts the default back', function (): void {
    $this->actingAs($this->teacher)->getJson('/api/v1/approvals/topics')->assertForbidden();
    $catalogue = $this->actingAs($this->admin)->getJson('/api/v1/approvals/topics')->assertOk();
    expect(collect($catalogue->json('areas'))->pluck('topics')->flatten(1)->firstWhere('key', 'attendance.correction'))
        ->toMatchArray(['steps' => ['The class teacher', 'Head teacher'], 'changed' => true]);

    $this->actingAs($this->admin)->putJson('/api/v1/approvals/topics/attendance.correction', ['steps' => [['kind' => 'relation', 'target' => 'nobody']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['steps.0']);
    $this->actingAs($this->admin)->putJson('/api/v1/approvals/topics/attendance.correction', ['steps' => []])
        ->assertOk()->assertJsonPath('labels', ['Anyone who may decide'])->assertJsonPath('chain.changed', false);
});
