<?php

declare(strict_types=1);

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
 * Fees in the parent portal (D-108): a confirmed parent sees each child's fees,
 * the statement and the receipts that include the child; nobody else does, and
 * nothing shows while the school does not show fees to families.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->travelTo('2026-09-10 09:00:00');
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'fees', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    app()->forgetScopedInstances();
    $this->school = TwoCampusSchool::make();
    $this->term = Ids::next();
    DB::table('academic_periods')->insert(['id' => $this->term, 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id('primary'),
        'name' => 'Term 1', 'position' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);
    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->staffWorkIn('division', $this->school->id('primary'));
    DB::table('fee_office_staff')->insert(['office_id' => 'school', 'user_id' => $this->admin->id, 'added_at' => now()]);

    $admit = function (string $first, string $phone): string {
        return (string) $this->actingAs($this->admin)->postJson('/api/v1/learners', [
            'first_name' => $first, 'other_names' => '', 'surname' => 'Banda', 'sex' => 'female', 'date_of_birth' => '2019-05-02',
            'class_id' => $this->school->id('class_primary_a'), 'admitted_on' => '2026-09-07', 'admission_number' => '',
            'contacts' => [['name' => 'Esther Banda', 'relationship' => 'mother', 'phone' => $phone, 'email' => '', 'main' => true],
                ['name' => 'Daniel Banda', 'relationship' => 'father', 'phone' => '0999 120 334', 'email' => '', 'main' => false]],
        ])->assertCreated()->json('id');
    };
    $this->alinafe = $admit('Alinafe', '0884 771 900');
    $this->chikondi = $admit('Chikondi', '0884 771 900');
    $this->wezi = $admit('Wezi', '0881 000 111');

    // Tuition 100,000 in two instalments: 60% on opening day, 40% by mid-term.
    $tuition = (string) DB::table('fee_items')->where('name', 'Tuition')->value('id');
    $path = "/api/v1/fees/terms/{$this->term}/{$this->school->id('primary')}";
    $this->actingAs($this->admin)->putJson($path, ['amounts' => [['item_id' => $tuition, 'level_code' => 'STD1', 'amount' => 10_000_000]],
        'plan' => [['label' => 'On opening day', 'due_on' => '2026-09-07', 'share_bp' => 6000], ['label' => 'By mid-term', 'due_on' => '2026-10-05', 'share_bp' => 4000]]])->assertOk();
    $this->actingAs($this->admin)->postJson($path.'/bill')->assertOk();

    $pay = fn (array $shares) => (string) $this->actingAs($this->admin)->postJson('/api/v1/fees/payments', ['method' => 'airtel_money', 'reference' => 'MP'.substr(Ids::next(), -10),
        'received_on' => now()->toDateString(), 'payer_name' => 'Esther Banda', 'amount' => array_sum(array_column($shares, 'amount')), 'shares' => $shares, 'tell' => false])
        ->assertCreated()->json('id');
    // One payment for both sisters, and one for Wezi of another family.
    $this->both = $pay([['learner_id' => $this->alinafe, 'amount' => 7_000_000], ['learner_id' => $this->chikondi, 'amount' => 2_000_000]]);
    $this->theirs = $pay([['learner_id' => $this->wezi, 'amount' => 1_000_000]]);

    // Esther has confirmed both her children; Mary has confirmed Wezi.
    $link = function (string $name, string $phone, array $learners): User {
        $user = User::factory()->create(['name' => $name, 'email' => null, 'phone' => $phone]);
        $guardian = Ids::next();
        DB::table('guardians')->insert(['id' => $guardian, 'phone' => $phone, 'name' => $name, 'user_id' => $user->id, 'created_at' => now()]);
        foreach ($learners as $learner) {
            DB::table('learner_guardian_relationships')->insert(['id' => Ids::next(), 'guardian_id' => $guardian, 'learner_id' => $learner,
                'contact_id' => (string) DB::table('learner_contacts')->where('learner_id', $learner)->where('relationship', 'mother')->value('id'), 'held_by' => $user->id, 'state' => 'active',
                'journey_ref' => 'enrolment.guardian_link@2026.1', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    };
    $this->esther = $link('Esther Banda', '+265884771900', [$this->alinafe, $this->chikondi]);
    $this->mary = $link('Mary Phiri', '+265881000111', [$this->wezi]);
    Auth::forgetGuards();
});

it('shows a parent each child\'s fees, with each child\'s part of a shared payment', function (): void {
    $fees = $this->actingAs($this->esther)->getJson("/api/v1/portal/children/{$this->alinafe}/fees")->assertOk();

    expect($fees->json('term.name'))->toBe('Term 1')
        ->and($fees->json('billed'))->toBe(10_000_000)
        ->and($fees->json('paid'))->toBe(7_000_000)
        ->and($fees->json('left'))->toBe(3_000_000)
        ->and(array_column($fees->json('instalments'), 'state'))->toBe(['paid', 'due'])
        ->and($fees->json('next_due'))->toBe(['on' => '2026-10-05', 'amount' => 3_000_000, 'overdue' => false])
        ->and($fees->json('payments'))->toHaveCount(1)
        ->and($fees->json('payments.0.amount'))->toBe(7_000_000)
        ->and($fees->json('payments.0.shared'))->toBeTrue()
        ->and($fees->json('payments.0.receipt'))->toBeTrue()
        ->and($fees->json('payments.0.this_term'))->toBeTrue();

    // Chikondi sees her own part of the same receipt.
    $sister = $this->actingAs($this->esther)->getJson("/api/v1/portal/children/{$this->chikondi}/fees")->assertOk();
    expect($sister->json('payments.0.id'))->toBe($this->both)->and($sister->json('payments.0.amount'))->toBe(2_000_000)
        ->and($sister->json('left'))->toBe(8_000_000)
        ->and(array_column($sister->json('instalments'), 'state'))->toBe(['overdue', 'not_due']);

    // The family at a glance: the total left, and the earliest date due.
    $family = $this->actingAs($this->esther)->getJson('/api/v1/portal/fees')->assertOk();
    expect($family->json('left'))->toBe(11_000_000)
        ->and($family->json('children'))->toHaveCount(2)
        ->and($family->json('next.child_id'))->toBe($this->chikondi)
        ->and($family->json('next.overdue'))->toBeTrue();
});

it('gives a parent the statement and a receipt that includes the child, and keeps a record of each download', function (): void {
    $statement = $this->actingAs($this->esther)->get("/api/v1/portal/children/{$this->alinafe}/fees/statement")->assertOk();
    expect($statement->headers->get('Content-Type'))->toBe('application/pdf')->and(substr((string) $statement->getContent(), 0, 4))->toBe('%PDF')
        // The file's details name the product, never the library that drew it (D-113).
        ->and((string) $statement->getContent())->toContain('/Producer (Acadly IQ-X)')->not->toContain('ReportLab');

    $receipt = $this->actingAs($this->esther)->get("/api/v1/portal/children/{$this->chikondi}/receipts/{$this->both}")->assertOk();
    expect($receipt->headers->get('Content-Type'))->toBe('application/pdf');

    // The same check code the fees desk gives.
    $number = (string) DB::table('fee_payments')->where('id', $this->both)->value('number');
    expect(DB::table('document_checks')->where('kind', 'fees.receipt')->where('reference', $number)->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'fees.family_downloaded')->where('actor_user_id', $this->esther->id)->count())->toBe(2);
});

it('answers not found to another parent, and for the receipt of another family', function (): void {
    $this->actingAs($this->mary)->getJson("/api/v1/portal/children/{$this->alinafe}/fees")->assertNotFound();
    $this->actingAs($this->mary)->get("/api/v1/portal/children/{$this->alinafe}/fees/statement")->assertNotFound();
    $this->actingAs($this->mary)->get("/api/v1/portal/children/{$this->alinafe}/receipts/{$this->both}")->assertNotFound();
    // Esther's own child, with Wezi's receipt: not hers.
    $this->actingAs($this->esther)->get("/api/v1/portal/children/{$this->alinafe}/receipts/{$this->theirs}")->assertNotFound();
    $this->actingAs($this->mary)->get("/api/v1/portal/children/{$this->wezi}/receipts/{$this->both}")->assertNotFound();
    // Mary sees only Wezi.
    expect(array_column($this->actingAs($this->mary)->getJson('/api/v1/portal/fees')->assertOk()->json('children'), 'id'))->toBe([$this->wezi]);
});

it('shows no fees while the school does not show them to families, or when Fees is off', function (): void {
    $this->actingAs($this->admin)->putJson('/api/v1/fees/portal', ['show_to_families' => false, 'how_to_pay' => 'Pay at the fees office.'])->assertOk()
        ->assertJson(['show_to_families' => false, 'how_to_pay' => 'Pay at the fees office.']);
    Auth::forgetGuards();

    $this->actingAs($this->esther)->getJson('/api/v1/portal/fees')->assertNotFound();
    $this->actingAs($this->esther)->getJson("/api/v1/portal/children/{$this->alinafe}/fees")->assertNotFound();
    $this->actingAs($this->esther)->get("/api/v1/portal/children/{$this->alinafe}/fees/statement")->assertNotFound();
    $this->actingAs($this->esther)->get("/api/v1/portal/children/{$this->alinafe}/receipts/{$this->both}")->assertNotFound();

    // On again; then the module off.
    $this->actingAs($this->admin)->putJson('/api/v1/fees/portal', ['show_to_families' => true, 'how_to_pay' => ''])->assertOk();
    Auth::forgetGuards();
    $this->actingAs($this->esther)->getJson("/api/v1/portal/children/{$this->alinafe}/fees")->assertOk()->assertJson(['how_to_pay' => '']);
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'fees', 'enabled' => false, 'reason' => 'Test.', 'switched_at' => now()]);
    app()->forgetScopedInstances();
    $this->actingAs($this->esther)->getJson("/api/v1/portal/children/{$this->alinafe}/fees")->assertNotFound();
    expect($this->actingAs($this->esther)->getJson('/api/v1/portal/fees')->assertOk()->json('children'))->toBe([]);
});

it('keeps how to pay to 600 characters without links, for the fees setup only', function (): void {
    $this->actingAs($this->admin)->getJson('/api/v1/fees/portal')->assertOk()->assertJson(['show_to_families' => true, 'how_to_pay' => '', 'max' => 600]);
    $this->actingAs($this->admin)->putJson('/api/v1/fees/portal', ['show_to_families' => true, 'how_to_pay' => str_repeat('a', 601)])->assertUnprocessable()->assertJsonValidationErrors(['how_to_pay']);
    $this->actingAs($this->admin)->putJson('/api/v1/fees/portal', ['show_to_families' => true, 'how_to_pay' => 'Pay at https://pay.example.test'])->assertUnprocessable()->assertJsonValidationErrors(['how_to_pay']);
    $this->actingAs($this->admin)->putJson('/api/v1/fees/portal', ['show_to_families' => true, 'how_to_pay' => 'Airtel Money: 0888 123 456.'])->assertOk();
    expect(DB::table('audit_events')->where('action', 'fees.portal_settings_saved')->count())->toBe(1);
    Auth::forgetGuards();
    $this->actingAs($this->esther)->getJson('/api/v1/fees/portal')->assertForbidden();
});
