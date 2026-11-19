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
use Acadly\Modules\Conduct\Contracts\ConductPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

/** @param  list<Permission>  $permissions */
function conductStaff(ScopeRef $at, array $permissions, string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $role = Role::query()->create(['key' => 'cnd_'.substr(Ids::next(), -12), 'name' => $name]);
    $role->grant($permissions);
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $at->type, 'scope_id' => $at->id, 'starts_at' => now()->subMinute()]);

    return $user;
}

beforeEach(function (): void {
    $this->travelTo('2026-11-17 10:30:00');
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'conduct', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    app()->forgetScopedInstances();
    $this->school = TwoCampusSchool::make();
    DB::table('academic_periods')->insert(['id' => Ids::next(), 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id('primary'),
        'name' => 'Term 1', 'position' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);
    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->staffWorkIn('division', $this->school->id('primary'));

    $admit = function (string $first, string $phone): string {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/learners', [
            'first_name' => $first, 'other_names' => '', 'surname' => 'Mwale', 'sex' => 'female', 'date_of_birth' => '2019-05-02',
            'class_id' => $this->school->id('class_primary_a'), 'admitted_on' => '2026-09-07', 'admission_number' => '',
            'contacts' => [['name' => 'Ruth Mwale', 'relationship' => 'mother', 'phone' => $phone, 'email' => '', 'main' => true],
                ['name' => 'Peter Mwale', 'relationship' => 'father', 'phone' => '0999 120 334', 'email' => '', 'main' => false]],
        ]);
        expect($response->status())->toBe(201, (string) $response->getContent());

        return (string) $response->json('id');
    };

    $this->tiwonge = $admit('Tiwonge', '0884 771 901');
    $this->kondwani = $admit('Kondwani', '0884 771 902');
    $this->teacher = conductStaff($this->school->scope(ScopeType::ClassGroup, 'class_primary_a'), [EnrolmentPermission::View, ConductPermission::View, ConductPermission::Record], 'Peter Banda');
    $this->deputy = conductStaff(ScopeRef::of(ScopeType::Institution, null), [EnrolmentPermission::View, ConductPermission::View, ConductPermission::FollowUp], 'Ruth Banda');
    $this->kind = fn (string $name): string => (string) DB::table('conduct_kinds')->where('name', $name)->value('id');
    $this->record = fn (User $by, string $kind, array $learners, array $more = []) => $this->actingAs($by)->postJson('/api/v1/conduct/entries', [
        'kind_id' => ($this->kind)($kind), 'learners' => $learners, 'happened_on' => '2026-11-17', 'what' => 'What happened.', ...$more,
    ]);
});

it('records commendations and incidents without points, tells families by level, and withdraws a mistake with a reason', function (): void {
    ($this->record)($this->teacher, 'Helping others', [$this->tiwonge])->assertCreated();
    $minor = ($this->record)($this->teacher, 'Late to class', [$this->tiwonge])->assertCreated()->json('entry_id');
    ($this->record)($this->teacher, 'Rudeness', [$this->tiwonge], ['tell' => true])->assertCreated();

    $told = DB::table('conduct_entries')->orderBy('created_at')->pluck('told_family')->map(fn ($v) => (bool) $v)->all();
    expect($told)->toBe([true, false, true]);

    $view = $this->actingAs($this->teacher)->getJson("/api/v1/conduct/learners/{$this->tiwonge}")->assertOk();
    expect(end($view->json()['terms']))->toMatchArray(['incidents' => 2, 'commendations' => 1, 'current' => true])
        ->and($view->json('can.follow_up'))->toBeFalse();

    $this->actingAs($this->teacher)->postJson("/api/v1/conduct/entries/{$minor}/withdraw", ['reason' => ''])->assertUnprocessable();
    $this->actingAs($this->teacher)->postJson("/api/v1/conduct/entries/{$minor}/withdraw", ['reason' => 'Recorded for the wrong learner.'])->assertOk();
    $this->actingAs($this->teacher)->postJson("/api/v1/conduct/entries/{$minor}/withdraw", ['reason' => 'Again.'])->assertUnprocessable();

    $view = $this->actingAs($this->teacher)->getJson("/api/v1/conduct/learners/{$this->tiwonge}")->json();
    expect(end($view['terms'])['incidents'])->toBe(1)
        ->and(collect($view['entries'])->firstWhere('id', $minor)['withdrawn']['reason'])->toBe('Recorded for the wrong learner.');
});

it('follows a serious incident through its journey; the reporter cannot decide it, and each learner needs an outcome', function (): void {
    $made = ($this->record)($this->admin, 'Fighting', [$this->tiwonge, $this->kondwani])->assertCreated()->json();
    $case = (string) $made['case_id'];
    $base = "/api/v1/conduct/incidents/{$case}";

    expect($this->actingAs($this->deputy)->getJson($base)->assertOk()->json())->toMatchArray(['number' => 'CND-2026-000001', 'state' => 'reported']);
    $this->actingAs($this->teacher)->postJson("{$base}/take-up")->assertForbidden();
    $this->actingAs($this->deputy)->postJson("{$base}/take-up")->assertOk();

    // The reporter holds every permission, but may not decide the incident they reported.
    $this->actingAs($this->admin)->postJson("{$base}/outcomes", ['learner_id' => $this->tiwonge, 'outcome' => 'warning', 'reason' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors(['outcome']);

    $this->actingAs($this->deputy)->postJson("{$base}/outcomes", ['learner_id' => $this->tiwonge, 'outcome' => 'suspension', 'reason' => 'Started the fight.'])
        ->assertUnprocessable()->assertJsonValidationErrors(['days']);
    $this->actingAs($this->deputy)->postJson("{$base}/outcomes", ['learner_id' => $this->tiwonge, 'outcome' => 'suspension', 'starts_on' => '2026-11-19', 'days' => 3, 'reason' => 'Started the fight.'])->assertCreated();

    // One learner still has no outcome: the incident cannot be decided yet.
    expect($this->actingAs($this->deputy)->postJson("{$base}/decide")->status())->toBeGreaterThanOrEqual(400);
    $this->actingAs($this->deputy)->postJson("{$base}/outcomes", ['learner_id' => $this->kondwani, 'outcome' => 'warning', 'reason' => 'Hit back.'])->assertCreated();
    $this->actingAs($this->deputy)->postJson("{$base}/decide")->assertOk();

    $this->actingAs($this->deputy)->postJson("{$base}/told", ['reason' => ''])->assertUnprocessable();
    $this->actingAs($this->deputy)->postJson("{$base}/met")->assertOk();
    $this->actingAs($this->deputy)->postJson("{$base}/close")->assertOk();

    $incident = $this->actingAs($this->deputy)->getJson($base)->json();
    expect($incident['state'])->toBe('closed')
        ->and(collect($incident['learners'])->firstWhere('id', $this->tiwonge)['outcome']['text'])->toBe('Suspension: 3 school days from 19 Nov')
        ->and(array_column($incident['notes'], 'text'))->toContain('Decided.');

    // A serious entry is withdrawn only from its incident.
    $this->actingAs($this->admin)->postJson("/api/v1/conduct/entries/{$made['entry_id']}/withdraw", ['reason' => 'x'])->assertUnprocessable();
});

it('lists learners to watch by the school\'s rules, each with its reason', function (): void {
    foreach (['Late to class', 'Uniform', 'Rudeness'] as $kind) {
        ($this->record)($this->teacher, $kind, [$this->kondwani])->assertCreated();
    }
    ($this->record)($this->teacher, 'Fighting', [$this->tiwonge])->assertCreated();

    $overview = $this->actingAs($this->deputy)->getJson('/api/v1/conduct')->assertOk()->json();
    $watch = collect($overview['watch'])->keyBy('id');
    expect($watch[$this->kondwani]['reasons'])->toBe(['3 incidents in 30 days.'])
        ->and($watch[$this->tiwonge]['reasons'])->toBe(['An open serious incident.'])
        ->and(count($overview['open_cases']))->toBe(1)
        ->and($overview['incidents'])->toBe(4)
        ->and($overview['levels']['serious'])->toBe(1);

    $record = $this->actingAs($this->admin)->getJson("/api/v1/learners/{$this->kondwani}")->assertOk();
    expect(array_column($record->json('parts'), 'key'))->toContain('conduct');
});

it('keeps conduct where the person may see it', function (): void {
    $made = ($this->record)($this->teacher, 'Fighting', [$this->tiwonge])->assertCreated()->json();
    $elsewhere = conductStaff($this->school->scope(ScopeType::Campus, 'campus_b'), [EnrolmentPermission::View, ConductPermission::View, ConductPermission::Record, ConductPermission::FollowUp], 'Other campus');

    $this->actingAs($elsewhere)->getJson("/api/v1/conduct/learners/{$this->tiwonge}")->assertNotFound();
    $this->actingAs($elsewhere)->getJson("/api/v1/conduct/incidents/{$made['case_id']}")->assertNotFound();
    $this->actingAs($elsewhere)->postJson("/api/v1/conduct/incidents/{$made['case_id']}/take-up")->assertNotFound();
    ($this->record)($elsewhere, 'Uniform', [$this->tiwonge])->assertNotFound();
    $this->actingAs($this->teacher)->getJson('/api/v1/conduct')->assertForbidden();
});
