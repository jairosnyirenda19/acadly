<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\TwoCampusSchool;

/*
 * Year end (D-088): the next school year set up as a copy of this one, then
 * the year-end run of a level group: promoted, repeat, on to the next level
 * group with its intake confirmed, and every outcome in effect on the first
 * day of the next year.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    app()->forgetScopedInstances();
    Queue::fake();
    $this->travelTo('2027-07-01 10:00:00');
    $this->school = TwoCampusSchool::make();

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);

    // A Standard 8 class at campus B this year, where Secondary is taught too.
    $this->std8 = Ids::next();
    DB::table('academic_classes')->insert(['id' => $this->std8, 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id('primary'),
        'campus_id' => $this->school->id('campus_b'), 'level_code' => 'STD8', 'stream' => null, 'name' => 'Standard 8', 'position' => 8,
        'created_at' => now(), 'updated_at' => now()]);

    $admit = fn (string $first, string $class, string $phone): string => $this->actingAs($this->admin)->postJson('/api/v1/learners', [
        'first_name' => $first, 'other_names' => '', 'surname' => 'Banda', 'sex' => 'female', 'date_of_birth' => '2015-03-04', 'class_id' => $class,
        'admitted_on' => '2026-09-08', 'admission_number' => '', 'contacts' => [
            ['name' => 'Ruth Banda', 'relationship' => 'mother', 'phone' => $phone, 'email' => '', 'main' => true],
            ['name' => 'Peter Banda', 'relationship' => 'father', 'phone' => '0888 410 220', 'email' => '', 'main' => false],
        ],
    ])->assertCreated()->json('id');

    $this->alinafe = $admit('Alinafe', $this->school->id('class_primary_a'), '0991 234 501');
    $this->mphatso = $admit('Mphatso', $this->school->id('class_primary_a'), '0991 234 502');
    $this->thoko = $admit('Thoko', $this->std8, '0991 234 503');
});

/** The class of the next school year for a level at a campus. */
function nextClass(object $test, string $year, string $level, string $campus): string
{
    return (string) DB::table('academic_classes')->where('year_id', $year)->where('level_code', $level)
        ->where('campus_id', $test->school->id($campus))->whereNull('ended_at')->value('id');
}

/** Sets up 2027/28 as proposed: a copy of this year, one year on. */
function setUpNextYear(object $test): string
{
    $proposal = $test->actingAs($test->admin)->getJson('/api/v1/academics/next-year')->assertOk()->json('next');

    expect($proposal)->toMatchArray(['saved' => false, 'name' => '2027/28', 'starts_on' => '2027-09-07', 'ends_on' => '2028-07-23']);

    $test->actingAs($test->admin)->putJson('/api/v1/academics/next-year', [
        'name' => '2027/28', 'starts_on' => '2027-09-06', 'ends_on' => '2028-07-21',
        'terms' => [['division_id' => $test->school->id('primary'), 'terms' => [
            ['name' => 'Term 1', 'starts_on' => '2027-09-06', 'ends_on' => '2027-12-10'], ['name' => 'Term 2', 'starts_on' => '2028-01-03', 'ends_on' => '2028-03-31'],
            ['name' => 'Term 3', 'starts_on' => '2028-04-24', 'ends_on' => '2028-07-21'],
        ]]],
        'classes' => array_map(fn (array $o): array => ['division_id' => $o['division_id'], 'campus_id' => $o['campus_id'], 'streams' => []], $proposal['classes']),
    ])->assertOk()->assertJsonPath('next.saved', true);

    return (string) DB::table('academic_years')->where('name', '2027/28')->value('id');
}

it('sets up the next school year as a copy of this one, after this one ends', function (): void {
    $this->actingAs($this->admin)->putJson('/api/v1/academics/next-year', ['name' => '2027/28', 'starts_on' => '2027-07-01', 'ends_on' => '2028-07-21'])
        ->assertUnprocessable()->assertJsonValidationErrors(['starts_on']);

    $next = setUpNextYear($this);

    expect(DB::table('academic_years')->count())->toBe(2)
        ->and(nextClass($this, $next, 'STD2', 'campus_a'))->not->toBe('')
        ->and(nextClass($this, $next, 'F1', 'campus_b'))->not->toBe('')
        ->and($this->actingAs($this->admin)->getJson('/api/v1/academics')->json('year.name'))->toBe('2026/27');
});

it('runs the year end: promoted, repeat with a reason, on to Form 1 with the intake confirmed, all in effect on the first day', function (): void {
    $this->actingAs($this->admin)->postJson("/api/v1/year-end/{$this->school->id('primary')}/open")->assertUnprocessable()->assertJsonValidationErrors(['year']);
    $next = setUpNextYear($this);

    $run = $this->actingAs($this->admin)->postJson("/api/v1/year-end/{$this->school->id('primary')}/open")->assertOk()->json();
    $row = fn (array $run, string $learner): array => collect($run['decisions'])->firstWhere('learner_id', $learner);

    expect($run['totals'])->toMatchArray(['learners' => 3, 'promoted' => 2, 'progressed' => 1, 'to_decide' => 3])
        ->and($row($run, $this->alinafe))->toMatchArray(['outcome' => 'promoted', 'to_class_id' => nextClass($this, $next, 'STD2', 'campus_a'), 'state' => 'proposed'])
        ->and($row($run, $this->thoko))->toMatchArray(['outcome' => 'progressed', 'to_class_id' => nextClass($this, $next, 'F1', 'campus_b')])
        ->and(collect($run['columns'])->pluck('label')->all())->toContain('Present');

    $mphatso = $row($run, $this->mphatso)['id'];
    $this->actingAs($this->admin)->putJson("/api/v1/year-end/decisions/{$mphatso}", ['outcome' => 'repeated', 'to_class_id' => nextClass($this, $next, 'STD1', 'campus_a')])
        ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->actingAs($this->admin)->putJson("/api/v1/year-end/decisions/{$mphatso}", ['outcome' => 'repeated', 'to_class_id' => nextClass($this, $next, 'STD3', 'campus_a'), 'reason' => 'Agreed with the family.'])
        ->assertUnprocessable()->assertJsonValidationErrors(['to_class_id']);
    $this->actingAs($this->admin)->putJson("/api/v1/year-end/decisions/{$mphatso}", ['outcome' => 'repeated', 'to_class_id' => nextClass($this, $next, 'STD1', 'campus_a'), 'reason' => 'Agreed with the family.'])
        ->assertOk()->assertJsonPath('totals.repeated', 1);

    $committed = $this->actingAs($this->admin)->postJson("/api/v1/year-end/{$this->school->id('primary')}/commit")->assertOk()->json();

    expect($committed['run']['state'])->toBe('committed')
        ->and($row($committed, $this->mphatso)['state'])->toBe('decided')
        ->and($row($committed, $this->thoko)['state'])->toBe('awaiting_intake')
        ->and(DB::table('placements')->where('learner_id', $this->alinafe)->count())->toBe(1);

    // A second commit changes nothing.
    $this->actingAs($this->admin)->postJson("/api/v1/year-end/{$this->school->id('primary')}/commit")->assertOk();
    expect(DB::table('journey_step_completions')->where('subject_id', $row($committed, $this->alinafe)['id'])->count())->toBe(1);

    // Secondary confirms its intake.
    $secondary = $this->actingAs($this->admin)->getJson("/api/v1/year-end/{$this->school->id('secondary')}")->assertOk()->json();
    expect($secondary['intake'])->toHaveCount(1)->and($secondary['intake'][0]['learner_id'])->toBe($this->thoko);
    $this->actingAs($this->admin)->postJson("/api/v1/year-end/decisions/{$secondary['intake'][0]['id']}/intake", ['accept' => true])->assertOk()->assertJsonPath('intake', []);

    // The first day of 2027/28.
    $this->travelTo('2027-09-06 06:00:00');
    $this->artisan('learners:year-start')->assertSuccessful();

    $now = fn (string $learner): object => DB::table('placements')->where('learner_id', $learner)->whereNull('ends_on')->first();

    expect($now($this->alinafe))->toMatchObject(['class_id' => nextClass($this, $next, 'STD2', 'campus_a'), 'entry_reason' => 'promotion', 'year_id' => $next])
        ->and($now($this->mphatso))->toMatchObject(['class_id' => nextClass($this, $next, 'STD1', 'campus_a'), 'entry_reason' => 'repeat', 'reason' => 'Agreed with the family.'])
        ->and($now($this->thoko))->toMatchObject(['class_id' => nextClass($this, $next, 'F1', 'campus_b'), 'entry_reason' => 'division_progression', 'division_id' => $this->school->id('secondary')])
        ->and(DB::table('placements')->where('learner_id', $this->alinafe)->whereNotNull('ends_on')->value('ends_on'))->toStartWith('2027-09-05')
        ->and(DB::table('progression_decisions')->where('state', 'effective')->count())->toBe(3);

    $this->artisan('learners:year-start')->expectsOutput('No outcome was due.')->assertSuccessful();
});

it('refuses the commit while a check stands, and refuses someone without the permission', function (): void {
    $next = setUpNextYear($this);
    DB::table('academic_periods')->insert(['id' => Ids::next(), 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id('primary'),
        'name' => 'Term 3', 'position' => 3, 'starts_on' => '2027-04-26', 'ends_on' => '2027-07-23', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->admin)->postJson("/api/v1/year-end/{$this->school->id('primary')}/open")->assertOk();

    $this->actingAs($this->admin)->postJson("/api/v1/year-end/{$this->school->id('primary')}/commit")
        ->assertUnprocessable()->assertJsonValidationErrors(['commit']);
    expect(DB::table('progression_decisions')->where('state', 'proposed')->count())->toBe(3);

    $clerk = User::factory()->create();
    $this->actingAs($clerk)->getJson("/api/v1/year-end/{$this->school->id('primary')}")->assertForbidden();
    expect($next)->not->toBe('');
});
