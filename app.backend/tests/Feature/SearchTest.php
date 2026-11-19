<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
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
 * Search everything (12-search, D-089): each reader finds only what their
 * own lists show them; a place they may not open is absent; hidden fields
 * are never matched.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    app()->forgetScopedInstances();
    Queue::fake();
    $this->travelTo('2026-11-18 10:00:00');
    $this->school = TwoCampusSchool::make();

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->staffWorkIn('division', $this->school->id('primary'));

    $admit = fn (string $first, string $class, string $phone): array => $this->actingAs($this->admin)->postJson('/api/v1/learners', [
        'first_name' => $first, 'other_names' => '', 'surname' => 'Banda', 'sex' => 'female', 'date_of_birth' => '2018-03-04', 'class_id' => $class,
        'admitted_on' => '2026-09-08', 'admission_number' => '', 'contacts' => [
            ['name' => 'Ruth Banda', 'relationship' => 'mother', 'phone' => $phone, 'email' => '', 'main' => true],
            ['name' => 'Peter Mwale', 'relationship' => 'father', 'phone' => '0888 410 220', 'email' => '', 'main' => false],
        ],
    ])->assertCreated()->json();

    $this->chikondi = $admit('Chikondi', $this->school->id('class_primary_a'), '0991 234 501');
    $this->thoko = $admit('Thoko', $this->school->id('class_primary_b'), '0991 234 502');

    // A teacher who sees the learners of one class at campus A only.
    $role = Role::query()->create(['key' => 'class_view_'.substr(Ids::next(), -6), 'name' => 'Class teacher']);
    DB::table('access_role_permissions')->insert(['role_id' => $role->id, 'permission_key' => EnrolmentPermission::View->value]);
    $this->teacher = User::factory()->create(['name' => 'Mercy Banda']);
    RoleAssignment::query()->create(['user_id' => $this->teacher->id, 'role_id' => $role->id, 'scope_type' => ScopeType::ClassGroup,
        'scope_id' => $this->school->id('class_primary_a'), 'starts_at' => now()->subMinute()]);
});

/** @return array<string, list<string>> group key => hit titles */
function found(object $test, User $who, string $q): array
{
    $groups = $test->actingAs($who)->getJson('/api/v1/search?q='.urlencode($q))->assertOk()->json('groups');

    return collect($groups)->mapWithKeys(fn (array $g): array => [$g['key'] => array_column($g['hits'], 'title')])->all();
}

it('finds learners, families and staff for the office, by any word at the start of a name', function (): void {
    expect(found($this, $this->admin, 'banda'))->toMatchArray([
        'learners' => ['Chikondi Banda', 'Thoko Banda'],
        'families' => ['Ruth Banda', 'Ruth Banda'],
        'staff' => ['Mercy Banda'],
    ])
        ->and(found($this, $this->admin, 'banda chik'))->toBe(['learners' => ['Chikondi Banda']])
        ->and(found($this, $this->admin, 'anda'))->toBe([]);
});

it('shows a class teacher only the learners of their class, and no group they may not open', function (): void {
    expect(found($this, $this->teacher, 'banda'))->toBe(['learners' => ['Chikondi Banda']])
        ->and(collect($this->actingAs($this->teacher)->getJson('/api/v1/search/places')->assertOk()->json('places'))->pluck('key')->all())->toBe(['learners', 'classes']);

    // A phone number is matched only for the office that keeps families.
    expect(found($this, $this->teacher, '0991 234 501'))->toBe([])
        ->and(found($this, $this->admin, '0991 234'))->toMatchArray(['families' => ['Ruth Banda', 'Ruth Banda']]);
});

it('never matches a hidden field, and finds a learner by admission number', function (): void {
    expect(found($this, $this->admin, '2018-03-04'))->toBe([])
        ->and(found($this, $this->admin, 'female'))->toBe([])
        ->and(found($this, $this->admin, (string) DB::table('learner_identifiers')->where('learner_id', $this->chikondi['id'])->where('kind', 'admission_number')->value('value')))->toMatchArray(['learners' => ['Chikondi Banda']]);
});

it('pages one group, says when there are more, and refuses one letter', function (): void {
    $page = $this->actingAs($this->admin)->getJson('/api/v1/search?q=banda&in=learners&limit=1')->assertOk()->json('groups');

    expect($page)->toHaveCount(1)->and($page[0]['hits'])->toHaveCount(1)->and($page[0]['more'])->toBeTrue();

    $next = $this->actingAs($this->admin)->getJson('/api/v1/search?q=banda&in=learners&limit=1&offset=1')->assertOk()->json('groups.0');
    expect($next['hits'][0]['title'])->toBe('Thoko Banda')->and($next['more'])->toBeFalse();

    $this->actingAs($this->admin)->getJson('/api/v1/search?q=b')->assertUnprocessable();
});
