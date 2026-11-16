<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->artisan('acadly:sync')->assertSuccessful();
    app()->forgetScopedInstances();
    $this->travelTo('2026-11-17 08:20:00');
    $this->school = TwoCampusSchool::make();

    foreach (['primary', 'secondary'] as $division) {
        DB::table('academic_periods')->insert(['id' => Ids::next(), 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id($division),
            'name' => 'Term 1', 'position' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->class = $this->school->id('class_secondary_b');
    $this->west = Ids::next();
    DB::table('academic_classes')->insert(['id' => $this->west, 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id('secondary'),
        'campus_id' => $this->school->id('campus_b'), 'level_code' => 'F1', 'stream' => 'West', 'name' => 'Form 1 West', 'position' => 2, 'created_at' => now(), 'updated_at' => now()]);
    $this->ids = [];

    foreach ([['Alinafe', 'Banda', 'female', 2], ['Chimwemwe', 'Chirwa', 'male', 1], ['Dalitso', 'Gondwe', 'male', 2], ['Fatsani', 'Kumwenda', 'female', 2], ['Gift', 'Lungu', 'male', 2]] as $i => [$first, $surname, $sex, $contacts]) {
        $people = [['name' => 'Ruth '.$surname, 'relationship' => 'mother', 'phone' => '0991 234 56'.$i, 'main' => true], ['name' => 'Peter '.$surname, 'relationship' => 'father', 'phone' => '0888 410 22'.$i]];
        $this->ids[$first] = $this->actingAs($this->admin)->postJson('/api/v1/learners', ['first_name' => $first, 'surname' => $surname, 'sex' => $sex, 'date_of_birth' => '2012-05-02',
            'class_id' => $i === 4 ? $this->west : $this->class, 'admitted_on' => sprintf('2026-09-%02d', 7 + $i), 'contacts' => $people])->assertCreated()->json('id');

        if ($contacts === 1) {
            DB::table('learner_contacts')->where('learner_id', $this->ids[$first])->where('is_main', false)->update(['removed_at' => now()]);
        }
    }
});

it('pages, sorts, searches and filters the learners on the server, with counts for each filter', function (): void {
    $page = $this->actingAs($this->admin)->getJson('/api/v1/learners/grid?per_page=10&sort=learner&dir=desc')->assertOk();
    expect($page->json('total'))->toBe(5)->and($page->json('pages'))->toBe(1)
        ->and(array_column($page->json('rows'), 'name'))->toBe(['Gift Lungu', 'Fatsani Kumwenda', 'Dalitso Gondwe', 'Chimwemwe Chirwa', 'Alinafe Banda'])
        ->and(collect($page->json('columns'))->firstWhere('key', 'date_of_birth')['shown'])->toBeFalse()
        ->and($page->json('bulk'))->toBe([['key' => 'change_class', 'label' => 'Change class']])
        ->and($page->json('can_export'))->toBeTrue();

    $classes = collect($page->json('filter_options'))->firstWhere('key', 'class')['options'];
    expect(array_column($classes, 'count', 'label'))->toMatchArray(['F1' => 4, 'Form 1 West' => 1]);

    $filtered = $this->actingAs($this->admin)->getJson('/api/v1/learners/grid?filter[class]='.$this->class.'&filter[sex]=male&per_page=10')->assertOk();
    expect(array_column($filtered->json('rows'), 'name'))->toBe(['Chimwemwe Chirwa', 'Dalitso Gondwe'])
        ->and($filtered->json('filters'))->toBe(['class' => [$this->class], 'sex' => ['male']]);

    $few = $this->actingAs($this->admin)->getJson('/api/v1/learners/grid?filter[state]=few_contacts')->assertOk();
    expect(array_column($few->json('rows'), 'name'))->toBe(['Chimwemwe Chirwa'])->and($few->json('rows.0.few_contacts'))->toBeTrue();

    $searched = $this->actingAs($this->admin)->getJson('/api/v1/learners/grid?q=gond&filter[admitted]=2026-09-08..2026-09-10')->assertOk();
    expect(array_column($searched->json('rows'), 'name'))->toBe(['Dalitso Gondwe']);

    // Paging: a page past the end shows the last page; an unknown size falls back to 25.
    $paged = $this->actingAs($this->admin)->getJson('/api/v1/learners/grid?per_page=10&page=9&sort=nope&filter[class]=not-a-class')->assertOk();
    expect($paged->json('page'))->toBe(1)->and($paged->json('sort'))->toBe('learner')->and($paged->json('filters'))->toBe([]);
});

it('keeps a person\'s columns and named views', function (): void {
    $this->actingAs($this->admin)->putJson('/api/v1/grids/learners/columns', ['shown' => ['class', 'date_of_birth']])->assertOk()
        ->assertJsonPath('shown', ['learner', 'class', 'date_of_birth']);
    $page = $this->actingAs($this->admin)->getJson('/api/v1/learners/grid')->assertOk();
    expect(array_column(array_filter($page->json('columns'), fn (array $c): bool => $c['shown']), 'key'))->toBe(['learner', 'class', 'date_of_birth']);

    $view = $this->actingAs($this->admin)->postJson('/api/v1/grids/learners/views', ['name' => 'Few contacts', 'state' => ['filters' => ['state' => ['few_contacts']], 'sort' => 'admitted_on']])
        ->assertCreated()->assertJsonPath('views.0.name', 'Few contacts')->assertJsonPath('views.0.state.filters.state', ['few_contacts'])->json('id');
    $this->actingAs($this->admin)->postJson('/api/v1/grids/learners/views', ['name' => 'Few contacts', 'state' => []])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->actingAs($this->admin)->putJson("/api/v1/grids/learners/views/{$view}", ['name' => 'One contact', 'state' => []])->assertOk()->assertJsonPath('views.0.name', 'One contact');

    // Views are a person's own.
    $other = User::factory()->create();
    RoleAssignment::query()->create(['user_id' => $other->id, 'role_id' => Role::query()->where('is_system', true)->value('id'), 'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->actingAs($other)->getJson('/api/v1/learners/grid')->assertOk()->assertJsonPath('views', []);
    $this->actingAs($other)->deleteJson("/api/v1/grids/learners/views/{$view}")->assertNotFound();
    $this->actingAs($this->admin)->deleteJson("/api/v1/grids/learners/views/{$view}")->assertOk()->assertJsonPath('views', []);
});

it('builds an export in the background, tells the person, and records the download', function (): void {
    $this->actingAs($this->admin)->putJson('/api/v1/grids/learners/columns', ['shown' => ['class', 'contacts']])->assertOk();
    $id = $this->actingAs($this->admin)->postJson('/api/v1/grids/learners/exports', ['state' => ['filters' => ['sex' => ['female']]]])->assertStatus(202)->json('id');

    $export = $this->actingAs($this->admin)->getJson("/api/v1/grids/exports/{$id}")->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('rows', 2)->assertJsonPath('list', 'Learners');
    expect(DB::table('notification_requests')->where('topic_key', 'notify.export_ready')->count())->toBe(1);

    $file = $this->actingAs($this->admin)->get("/api/v1/grids/exports/{$id}/file")->assertOk();
    expect($file->headers->get('content-type'))->toContain('spreadsheetml')
        ->and(DB::table('audit_events')->where('action', 'documents.export_downloaded')->count())->toBe(1);

    $other = User::factory()->create();
    $this->actingAs($other)->getJson("/api/v1/grids/exports/{$id}")->assertNotFound();

    $this->travel(8)->days();
    $this->artisan('grids:purge-exports')->assertSuccessful();
    $this->actingAs($this->admin)->getJson("/api/v1/grids/exports/{$id}")->assertOk()->assertJsonPath('status', 'expired');
    $this->actingAs($this->admin)->get("/api/v1/grids/exports/{$id}/file")->assertNotFound();
    expect($export->json('expires_at'))->not->toBeNull();
});

it('changes the class of every learner that matches, and says which were not moved and why', function (): void {
    $result = $this->actingAs($this->admin)->postJson('/api/v1/grids/learners/bulk', ['action' => 'change_class', 'all' => true, 'state' => ['filters' => ['sex' => ['male']]],
        'input' => ['class_id' => $this->west, 'from' => '2026-11-17', 'reason' => 'Streams balanced.']])->assertOk();
    expect($result->json('done'))->toBe(2)
        ->and($result->json('refused'))->toHaveCount(1)
        ->and($result->json('refused.0.name'))->toBe('Gift Lungu');

    $this->actingAs($this->admin)->postJson('/api/v1/grids/learners/bulk', ['action' => 'delete_all', 'ids' => [$this->ids['Alinafe']], 'state' => []])->assertForbidden();
    $this->actingAs($this->admin)->postJson('/api/v1/grids/learners/bulk', ['action' => 'change_class', 'ids' => [Ids::next()], 'state' => []])->assertUnprocessable();
});

it('refuses the list to someone who may see no learner', function (): void {
    $nobody = User::factory()->create();
    $this->actingAs($nobody)->getJson('/api/v1/learners/grid')->assertForbidden();
    $this->actingAs($nobody)->postJson('/api/v1/grids/learners/exports', ['state' => []])->assertForbidden();
    $this->actingAs($nobody)->getJson('/api/v1/grids/nothing/columns')->assertStatus(405);
    $this->actingAs($nobody)->putJson('/api/v1/grids/nothing/columns', ['shown' => []])->assertNotFound();
});

it('lists people and sent messages on the same grid', function (): void {
    $people = $this->actingAs($this->admin)->getJson('/api/v1/people/grid?sort=name')->assertOk();
    expect(array_column($people->json('rows'), 'name'))->toContain('Grace Phiri')
        ->and($people->json('bulk'))->toBe([['key' => 'resend', 'label' => 'Resend invitations']]);
    $active = $this->actingAs($this->admin)->getJson('/api/v1/people/grid?filter[state]=active')->assertOk();
    expect(array_column($active->json('rows'), 'state'))->each->toBe('active');

    $this->actingAs($this->admin)->postJson('/api/v1/messages/test', ['channel' => 'sms', 'address' => '0991 234 567'])->assertSuccessful();
    $sent = $this->actingAs($this->admin)->getJson('/api/v1/messages/sent/grid?filter[channel]=sms&filter[day]=2026-11-17..2026-11-17')->assertOk();
    expect($sent->json('total'))->toBe(1)->and($sent->json('rows.0.notice'))->toBe('Test message')
        ->and(collect($sent->json('filter_options'))->firstWhere('key', 'channel')['options'][0]['count'])->toBe(1);
});
