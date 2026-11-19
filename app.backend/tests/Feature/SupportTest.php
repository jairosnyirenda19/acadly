<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Support\Contracts\SupportPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

/** @param  list<Permission>  $permissions */
function supportStaff(ScopeRef $at, array $permissions, string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $role = Role::query()->create(['key' => 'sup_'.substr(Ids::next(), -12), 'name' => $name]);
    $role->grant($permissions);
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $at->type, 'scope_id' => $at->id, 'starts_at' => now()->subMinute()]);

    return $user;
}

beforeEach(function (): void {
    $this->travelTo('2026-11-17 10:30:00');
    $this->artisan('acadly:sync')->assertSuccessful();

    foreach (['attendance', 'conduct', 'support', 'fees'] as $module) {
        DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => $module, 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    }

    app()->forgetScopedInstances();
    $this->school = TwoCampusSchool::make();
    DB::table('academic_periods')->insert(['id' => Ids::next(), 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id('primary'),
        'name' => 'Term 1', 'position' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);
    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->staffWorkIn('division', $this->school->id('primary'));
    $this->class = $this->school->id('class_primary_a');

    $admit = function (string $first, string $phone): string {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/learners', [
            'first_name' => $first, 'other_names' => '', 'surname' => 'Mwale', 'sex' => 'female', 'date_of_birth' => '2019-05-02',
            'class_id' => $this->class, 'admitted_on' => '2026-09-07', 'admission_number' => '',
            'contacts' => [['name' => 'Ruth Mwale', 'relationship' => 'mother', 'phone' => $phone, 'email' => '', 'main' => true],
                ['name' => 'Peter Mwale', 'relationship' => 'father', 'phone' => '0999 120 334', 'email' => '', 'main' => false]],
        ]);
        expect($response->status())->toBe(201, (string) $response->getContent());

        return (string) $response->json('id');
    };

    $this->tiwonge = $admit('Tiwonge', '0884 771 911');
    $this->kondwani = $admit('Kondwani', '0884 771 912');
    $this->teacher = supportStaff($this->school->scope(ScopeType::ClassGroup, 'class_primary_a'), [EnrolmentPermission::View, SupportPermission::View, SupportPermission::Lead], 'Peter Banda');

    // Kondwani is away for five school days in a row.
    foreach (['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-16'] as $day) {
        $this->actingAs($this->admin)->postJson("/api/v1/registers/class/{$this->class}", ['day' => $day, 'marks' => [
            ['learner_id' => $this->tiwonge, 'status' => 'present', 'note' => null],
            ['learner_id' => $this->kondwani, 'status' => 'absent', 'note' => null],
        ]])->assertOk();
    }

    // Tiwonge has three incidents this month.
    foreach (['Late to class', 'Uniform', 'Rudeness'] as $kind) {
        $this->actingAs($this->admin)->postJson('/api/v1/conduct/entries', ['kind_id' => (string) DB::table('conduct_kinds')->where('name', $kind)->value('id'),
            'learners' => [$this->tiwonge], 'happened_on' => '2026-11-16', 'what' => 'What happened.'])->assertCreated();
    }
});

it('lists learners to watch from attendance and conduct, each with its reason, and never from fees', function (): void {
    $watch = $this->actingAs($this->teacher)->getJson('/api/v1/support')->assertOk()->json();
    $rows = collect($watch['learners'])->keyBy('learner_id');

    expect(array_column($rows[$this->kondwani]['reasons'], 'text'))->toContain('5 absences in the last 14 days.')
        ->and(array_column($rows[$this->tiwonge]['reasons'], 'source'))->toBe(['conduct'])
        ->and(array_column($watch['sources'], 'key'))->not->toContain('fees')
        ->and($watch['totals']['without_plan'])->toBe(2);

    // The school turns the absence rule off: the signal goes.
    $this->actingAs($this->admin)->putJson('/api/v1/support/rules', ['values' => ['attendance.absences' => 0, 'attendance.present_below' => 0]])->assertOk();
    $rows = collect($this->actingAs($this->teacher)->getJson('/api/v1/support')->json('learners'))->keyBy('learner_id');
    expect($rows->has($this->kondwani))->toBeFalse();

    // A known signal is set aside with a reason, and stays on the list marked so.
    $this->actingAs($this->teacher)->postJson("/api/v1/support/learners/{$this->tiwonge}/set-aside", ['until' => '2026-11-30', 'reason' => ''])->assertUnprocessable();
    $this->actingAs($this->teacher)->postJson("/api/v1/support/learners/{$this->tiwonge}/set-aside", ['until' => '2026-11-30', 'reason' => 'Known to the deputy head.'])->assertOk();
    $row = collect($this->actingAs($this->teacher)->getJson('/api/v1/support')->json('learners'))->firstWhere('learner_id', $this->tiwonge);
    expect($row['set_aside']['reason'])->toBe('Known to the deputy head.');
});

it('opens a plan, agrees it, reviews each goal and closes it with a named outcome', function (): void {
    $opening = $this->actingAs($this->teacher)->getJson("/api/v1/support/learners/{$this->tiwonge}/opening")->assertOk()->json();
    expect($opening['lead'])->toBe($this->teacher->id)->and($opening['tell_family'])->toBeTrue();

    $id = $this->actingAs($this->teacher)->postJson('/api/v1/support/plans', ['learner_id' => $this->tiwonge, 'title' => 'Conduct in lessons', 'concern' => 'Three incidents this month.',
        'goals' => [['text' => 'No incident in lessons', 'measure' => 'From the conduct record.', 'by_when' => 'By 11 Dec']]])->assertCreated()->json('id');
    $base = "/api/v1/support/plans/{$id}";

    $plan = $this->actingAs($this->teacher)->getJson($base)->assertOk()->json();
    expect($plan['state'])->toBe('raised')->and($plan['signals'])->toBe(['Conduct: 3 incidents in 30 days.'])->and($plan['tell_family'])->toBeTrue();

    $this->actingAs($this->teacher)->postJson("{$base}/actions", ['who' => 'Peter Banda', 'what' => 'Check in each morning.', 'when' => 'Daily'])->assertOk();
    $this->actingAs($this->teacher)->postJson("{$base}/reviews", ['goals' => [], 'next_review_on' => '2026-12-01'])->assertUnprocessable();
    $this->actingAs($this->teacher)->postJson("{$base}/agree")->assertOk();

    $goal = $plan['goals'][0]['id'];
    $this->actingAs($this->teacher)->postJson("{$base}/reviews", ['goals' => [$goal => 'excellent'], 'next_review_on' => '2026-12-01'])->assertUnprocessable();
    $this->actingAs($this->teacher)->postJson("{$base}/reviews", ['goals' => [$goal => 'partly'], 'notes' => 'One incident since.', 'next_review_on' => '2026-12-01'])->assertOk();

    $this->actingAs($this->teacher)->postJson("{$base}/close", ['outcome' => 'goals_met', 'note' => ''])->assertUnprocessable();
    $this->actingAs($this->teacher)->postJson("{$base}/close", ['outcome' => 'partly_met', 'note' => 'Better in lessons; keep an eye.'])->assertOk();

    $plan = $this->actingAs($this->teacher)->getJson($base)->json();
    expect($plan['state'])->toBe('closed')->and($plan['outcome_label'])->toBe('Partly met')
        ->and($plan['goals'][0]['state'])->toBe('partly')->and($plan['reviews'][0]['of'])->toBe(1);

    $learner = $this->actingAs($this->teacher)->getJson("/api/v1/support/learners/{$this->tiwonge}")->assertOk()->json();
    $incidents = collect($learner['trends']['rows'])->firstWhere('key', 'conduct.incidents');
    $present = collect($learner['trends']['rows'])->firstWhere('key', 'attendance.present');
    $term = end($learner['trends']['terms'])['key'];
    expect($incidents['values'][$term])->toEqual(3)->and($present['values'][$term])->toEqual(100)
        ->and($learner['plans'][0]['outcome'])->toBe('partly_met');
});

it('keeps a confidential plan from those who may not see it, and support where the person may see it', function (): void {
    $this->actingAs($this->teacher)->postJson('/api/v1/support/plans', ['learner_id' => $this->kondwani, 'title' => 'Counselling', 'concern' => 'x', 'confidential' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['confidential']);

    $id = $this->actingAs($this->admin)->postJson('/api/v1/support/plans', ['learner_id' => $this->kondwani, 'title' => 'Counselling', 'concern' => 'Family loss.', 'confidential' => true])
        ->assertCreated()->json('id');

    expect($this->actingAs($this->admin)->getJson("/api/v1/support/plans/{$id}")->json('tell_family'))->toBeFalse()
        ->and($this->actingAs($this->teacher)->getJson("/api/v1/support/learners/{$this->kondwani}")->json('plans'))->toBe([]);
    $this->actingAs($this->teacher)->getJson("/api/v1/support/plans/{$id}")->assertNotFound();

    $elsewhere = supportStaff($this->school->scope(ScopeType::Campus, 'campus_b'), [EnrolmentPermission::View, SupportPermission::View, SupportPermission::Lead, SupportPermission::Confidential], 'Other campus');
    $this->actingAs($elsewhere)->getJson("/api/v1/support/learners/{$this->tiwonge}")->assertNotFound();
    $this->actingAs($elsewhere)->getJson("/api/v1/support/plans/{$id}")->assertNotFound();
    $this->actingAs($elsewhere)->postJson('/api/v1/support/plans', ['learner_id' => $this->tiwonge, 'title' => 'x', 'concern' => 'x'])->assertNotFound();
    expect(collect($this->actingAs($elsewhere)->getJson('/api/v1/support')->json('learners'))->pluck('learner_id')->all())->toBe([]);

    $record = $this->actingAs($this->admin)->getJson("/api/v1/learners/{$this->kondwani}")->assertOk();
    expect(array_column($record->json('parts'), 'key'))->toContain('support');
});
