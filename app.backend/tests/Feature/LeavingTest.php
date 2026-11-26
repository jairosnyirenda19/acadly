<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoCampusSchool;

/*
 * A learner leaves during the year (D-114): off every register from the next
 * day, the record kept, the family reading for a year, the fees not yet due
 * waived only after the bursar's yes, a transfer letter with its check code,
 * and coming back as a new placement.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->travelTo('2026-09-20 09:00:00');
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'fees', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    app()->forgetScopedInstances();
    $this->school = TwoCampusSchool::make();
    $this->term = Ids::next();
    DB::table('academic_periods')->insert(['id' => $this->term, 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id('primary'),
        'name' => 'Term 1', 'position' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);
    $this->bursar = User::factory()->create(['name' => 'Ruth Gondwe']);
    RoleAssignment::query()->create(['user_id' => $this->bursar->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->staffWorkIn('division', $this->school->id('primary'));
    $this->office = User::factory()->create(['name' => 'Esther Banda']);
    $role = Role::query()->create(['key' => 'off_'.substr(Ids::next(), -12), 'name' => 'Office']);
    $role->grant([EnrolmentPermission::View, EnrolmentPermission::Manage]);
    RoleAssignment::query()->create(['user_id' => $this->office->id, 'role_id' => $role->id, 'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->class = $this->school->id('class_primary_a');

    $this->learner = (string) $this->actingAs($this->bursar)->postJson('/api/v1/learners', [
        'first_name' => 'Tiwonge', 'other_names' => '', 'surname' => 'Mwale', 'sex' => 'female', 'date_of_birth' => '2016-05-02',
        'class_id' => $this->class, 'admitted_on' => '2026-09-07', 'admission_number' => '',
        'contacts' => [['name' => 'Ruth Mwale', 'relationship' => 'mother', 'phone' => '0884 771 901', 'email' => '', 'main' => true],
            ['name' => 'Peter Mwale', 'relationship' => 'father', 'phone' => '0999 120 334', 'email' => '', 'main' => false]],
    ])->assertCreated()->json('id');

    // Tuition 100,000 in two instalments: 60% on opening day, 40% on 5 October.
    $tuition = (string) DB::table('fee_items')->where('name', 'Tuition')->value('id');
    $path = "/api/v1/fees/terms/{$this->term}/{$this->school->id('primary')}";
    $this->actingAs($this->bursar)->putJson($path, ['amounts' => [['item_id' => $tuition, 'level_code' => 'STD1', 'amount' => 10_000_000]],
        'plan' => [['label' => 'On opening day', 'due_on' => '2026-09-07', 'share_bp' => 6000], ['label' => 'By mid-term', 'due_on' => '2026-10-05', 'share_bp' => 4000]]])->assertOk();
    $this->actingAs($this->bursar)->postJson($path.'/bill')->assertOk();
    Auth::forgetGuards();
});

it('takes a learner off every register from the day after the last day, and keeps the record', function (): void {
    $this->actingAs($this->office)->postJson("/api/v1/learners/{$this->learner}/leave", ['last_day' => '2026-09-25', 'kind' => 'other', 'destination' => '', 'note' => '', 'waive' => false])
        ->assertUnprocessable()->assertJsonValidationErrors(['note']);
    $this->actingAs($this->office)->postJson("/api/v1/learners/{$this->learner}/leave", ['last_day' => '2026-09-25', 'kind' => 'moved_school',
        'destination' => 'Lilongwe Academy', 'note' => 'Father transferred.', 'waive' => false])->assertOk();

    $record = $this->actingAs($this->office)->getJson("/api/v1/learners/{$this->learner}")->assertOk();
    expect($record->json('left'))->toMatchArray(['last_day' => '2026-09-25', 'kind' => 'moved_school', 'kind_label' => 'Moved to another school', 'destination' => 'Lilongwe Academy', 'by' => 'Esther Banda'])
        ->and($record->json('status'))->toBe('left');

    $in = fn (string $day): array => array_column(app(EnrolmentDirectory::class)->learnersIn($this->class, $day), 'id');
    expect($in('2026-09-25'))->toContain($this->learner)->and($in('2026-09-28'))->not->toContain($this->learner);

    // A second time: not in a class now.
    $this->actingAs($this->office)->postJson("/api/v1/learners/{$this->learner}/leave", ['last_day' => '2026-09-25', 'kind' => 'moved_away', 'waive' => false])->assertUnprocessable();
    expect(DB::table('audit_events')->where('action', 'enrolment.learner_left')->count())->toBe(1)
        ->and(DB::table('fee_leaving_waivers')->count())->toBe(0);
});

it('waives the fees not yet due only after the bursar says yes', function (): void {
    $this->actingAs($this->office)->postJson("/api/v1/learners/{$this->learner}/leave", ['last_day' => '2026-09-25', 'kind' => 'moved_away', 'waive' => true])->assertOk();
    $this->artisan('events:publish')->assertSuccessful();

    $waiver = DB::table('fee_leaving_waivers')->where('learner_id', $this->learner)->first();
    expect($waiver?->total_minor)->toBe(4_000_000);
    $request = (string) DB::table('approval_requests')->where('topic_key', 'fees.waive_on_leaving')->value('id');

    // Nothing waived yet; the person who recorded it cannot approve it.
    expect(DB::table('fee_adjustments')->where('learner_id', $this->learner)->count())->toBe(0);
    $this->actingAs($this->office)->postJson("/api/v1/approvals/{$request}/answer", ['approve' => true])->assertForbidden();
    $this->actingAs($this->bursar)->postJson("/api/v1/approvals/{$request}/answer", ['approve' => true])->assertOk();

    $adjustment = DB::table('fee_adjustments')->where('learner_id', $this->learner)->first(['kind', 'amount_minor']);
    expect($adjustment?->kind)->toBe('waiver')->and($adjustment?->amount_minor)->toBe(4_000_000);
});

it('makes a transfer letter with a check code, and places a learner who comes back again', function (): void {
    $this->actingAs($this->office)->get("/api/v1/learners/{$this->learner}/transfer-letter")->assertUnprocessable();
    $this->actingAs($this->office)->postJson("/api/v1/learners/{$this->learner}/leave", ['last_day' => '2026-09-18', 'kind' => 'moved_school', 'waive' => false])->assertOk();

    $letter = $this->actingAs($this->office)->get("/api/v1/learners/{$this->learner}/transfer-letter")->assertOk();
    expect($letter->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(DB::table('document_checks')->where('kind', 'enrolment.transfer')->count())->toBe(1);

    $back = $this->actingAs($this->office)->getJson("/api/v1/learners/{$this->learner}")->json('return_to');
    expect(array_column($back, 'id'))->toContain($this->class);
    $this->actingAs($this->office)->postJson("/api/v1/learners/{$this->learner}/return", ['class_id' => $this->class, 'from' => '2026-09-18'])->assertUnprocessable();
    $this->travelTo('2026-09-21 09:00:00');
    $this->actingAs($this->office)->postJson("/api/v1/learners/{$this->learner}/return", ['class_id' => $this->class, 'from' => '2026-09-22'])->assertUnprocessable();
    $this->actingAs($this->office)->postJson("/api/v1/learners/{$this->learner}/return", ['class_id' => $this->class, 'from' => '2026-09-21'])->assertOk();

    $record = $this->actingAs($this->office)->getJson("/api/v1/learners/{$this->learner}")->assertOk();
    expect($record->json('left'))->toBeNull()->and($record->json('status'))->toBe('active')
        ->and(DB::table('placements')->where('learner_id', $this->learner)->count())->toBe(2);
});

it('lets the family read the record for a year after the last day, then no more', function (): void {
    $user = User::factory()->create(['name' => 'Ruth Mwale', 'email' => null, 'phone' => '+265884771901']);
    $guardian = Ids::next();
    DB::table('guardians')->insert(['id' => $guardian, 'phone' => '+265884771901', 'name' => 'Ruth Mwale', 'user_id' => $user->id, 'created_at' => now()]);
    DB::table('learner_guardian_relationships')->insert(['id' => Ids::next(), 'guardian_id' => $guardian, 'learner_id' => $this->learner,
        'contact_id' => (string) DB::table('learner_contacts')->where('learner_id', $this->learner)->where('relationship', 'mother')->value('id'), 'held_by' => $user->id, 'state' => 'active',
        'journey_ref' => 'enrolment.guardian_link@2026.1', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->office)->postJson("/api/v1/learners/{$this->learner}/leave", ['last_day' => '2026-09-18', 'kind' => 'moved_away', 'waive' => false])->assertOk();
    Auth::forgetGuards();

    $child = collect($this->actingAs($user)->getJson('/api/v1/portal/family')->assertOk()->json('children'))->firstWhere('id', $this->learner);
    expect(collect($child['lines'])->firstWhere('key', 'left'))->toMatchArray(['word' => 'left', 'label' => '2026-09-18']);

    $this->travelTo('2027-09-20 09:00:00');
    expect($this->actingAs($user)->getJson('/api/v1/portal/family')->json('children'))->toBe([]);
});
