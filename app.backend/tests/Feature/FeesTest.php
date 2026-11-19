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
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

/** Kwacha to tambala: money is kept as whole tambala. */
function mk(int $kwacha): int
{
    return $kwacha * 100;
}

/** @param  list<Permission>  $permissions */
function feeStaff(ScopeRef $at, array $permissions, string $name = 'Bursar'): User
{
    $user = User::factory()->create(['name' => $name]);
    $role = Role::query()->create(['key' => 'fee_'.substr(Ids::next(), -12), 'name' => $name]);
    $role->grant($permissions);
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $at->type, 'scope_id' => $at->id, 'starts_at' => now()->subMinute()]);

    return $user;
}

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

    $admit = function (string $first, string $class, string $phone): string {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/learners', [
            'first_name' => $first, 'other_names' => '', 'surname' => 'Banda', 'sex' => 'female', 'date_of_birth' => '2019-05-02',
            'class_id' => $this->school->id($class), 'admitted_on' => '2026-09-07', 'admission_number' => '',
            'contacts' => [['name' => 'Esther Banda', 'relationship' => 'mother', 'phone' => $phone, 'email' => '', 'main' => true],
                ['name' => 'Daniel Banda', 'relationship' => 'father', 'phone' => '0999 120 334', 'email' => '', 'main' => false]],
        ]);
        expect($response->status())->toBe(201, (string) $response->getContent());

        return (string) $response->json('id');
    };

    $this->alinafe = $admit('Alinafe', 'class_primary_a', '0884 771 900');
    $this->chikondi = $admit('Chikondi', 'class_primary_a', '0884 771 900');
    $this->item = fn (string $name): string => (string) DB::table('fee_items')->where('name', $name)->value('id');
    $this->path = "/api/v1/fees/terms/{$this->term}/{$this->school->id('primary')}";

    // Tuition 140,000 and development fund 25,000; boarding 210,000 for boarders. 50% · 30% · 20%.
    $this->actingAs($this->admin)->putJson($this->path, [
        'amounts' => [
            ['item_id' => ($this->item)('Tuition'), 'level_code' => 'STD1', 'amount' => mk(140000)],
            ['item_id' => ($this->item)('Development fund'), 'level_code' => 'STD1', 'amount' => mk(25000)],
            ['item_id' => ($this->item)('Boarding'), 'level_code' => 'STD1', 'amount' => mk(210000)],
        ],
        'plan' => [
            ['label' => 'On opening day', 'due_on' => '2026-09-07', 'share_bp' => 5000],
            ['label' => 'By mid-term', 'due_on' => '2026-10-05', 'share_bp' => 3000],
            ['label' => 'By week 10', 'due_on' => '2026-11-09', 'share_bp' => 2000],
        ],
    ])->assertOk();

    $this->pay = fn (int $amount, array $shares, string $method = 'cash', ?string $reference = null) => $this->actingAs($this->admin)->postJson('/api/v1/fees/payments', [
        'method' => $method, 'reference' => $reference, 'received_on' => now()->toDateString(), 'payer_name' => 'Esther Banda',
        'amount' => $amount, 'shares' => $shares, 'tell' => false,
    ]);
});

it('bills the term in instalments; a part payment pays the earliest instalment and carries on; more than owed is credit', function (): void {
    // Chikondi boards: the optional item is billed only to her.
    $this->actingAs($this->admin)->putJson("/api/v1/fees/learners/{$this->chikondi}/optional", ['items' => [($this->item)('Boarding')]])->assertOk();
    expect($this->actingAs($this->admin)->getJson($this->path)->json('to_bill'))->toBe(['learners' => 2, 'total' => mk(165000 + 375000)]);
    $this->actingAs($this->admin)->postJson($this->path.'/bill')->assertOk()->assertJson(['learners' => 2, 'total' => mk(540000)]);

    $fees = fn (string $id) => $this->actingAs($this->admin)->getJson("/api/v1/fees/learners/{$id}")->assertOk();
    expect(array_column($fees($this->alinafe)->json('standing.instalments'), 'amount'))->toBe([mk(82500), mk(49500), mk(33000)]);

    // 100,000: all of instalment 1 (82,500), and 17,500 of instalment 2.
    $id = ($this->pay)(mk(100000), [['learner_id' => $this->alinafe, 'amount' => mk(100000)]])->assertCreated()->json('id');
    $receipt = $this->actingAs($this->admin)->getJson("/api/v1/fees/payments/{$id}")->assertOk();
    expect($receipt->json('number'))->toBe('RCT-2026-000001')
        ->and($receipt->json('shares.0.applied'))->toBe([['label' => 'Term 1 fees · On opening day', 'amount' => mk(82500)], ['label' => 'Term 1 fees · By mid-term', 'amount' => mk(17500)]])
        ->and($receipt->json('shares.0.balance_after'))->toBe(mk(65000));
    expect(array_column($fees($this->alinafe)->json('standing.instalments'), 'state'))->toBe(['paid', 'part', 'due']);

    // After mid-term, the rest of instalment 2 is overdue.
    $this->travelTo('2026-10-10 09:00:00');
    $standing = $fees($this->alinafe)->json('standing');
    expect($standing['due_now'])->toBe(mk(32000))->and($standing['overdue_since'])->toBe('2026-10-05')
        ->and(array_column($standing['instalments'], 'state'))->toBe(['paid', 'overdue', 'due']);

    // One payment for both children, one receipt; the same Airtel Money reference is refused twice.
    $both = ($this->pay)(mk(150000), [['learner_id' => $this->alinafe, 'amount' => mk(90000)], ['learner_id' => $this->chikondi, 'amount' => mk(60000)]], 'airtel_money', 'MP261010.0942.C77412')
        ->assertCreated()->json('id');
    expect($this->actingAs($this->admin)->getJson("/api/v1/fees/payments/{$both}")->json('number'))->toBe('RCT-2026-000002');
    ($this->pay)(mk(1000), [['learner_id' => $this->alinafe, 'amount' => mk(1000)]], 'airtel_money', 'mp261010.0942.c77412')->assertUnprocessable()->assertJsonValidationErrors(['reference']);
    ($this->pay)(mk(1000), [['learner_id' => $this->alinafe, 'amount' => mk(900)]])->assertUnprocessable()->assertJsonValidationErrors(['shares']);

    // Alinafe owed 65,000; 90,000 leaves 25,000 of credit.
    $alinafe = $fees($this->alinafe)->json();
    expect($alinafe['standing']['left'])->toBe(0)->and($alinafe['standing']['credit'])->toBe(mk(25000))->and($alinafe['standing']['balance'])->toBe(-mk(25000))
        ->and(end($alinafe['statement'])['balance'])->toBe(-mk(25000))
        ->and(array_column($alinafe['statement'], 'kind'))->toBe(['charge', 'payment', 'payment'])
        ->and($alinafe['siblings'][0]['id'])->toBe($this->chikondi);

    // Billing again bills nobody new, and the term's fees no longer change.
    $this->actingAs($this->admin)->postJson($this->path.'/bill')->assertOk()->assertJson(['learners' => 0]);
    $this->actingAs($this->admin)->putJson($this->path, ['amounts' => [], 'plan' => [['label' => 'All', 'due_on' => '2026-09-07', 'share_bp' => 10000]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['term']);
});

it('cancels a receipt with a reason: the payment stays on the statement and what was paid is owed again', function (): void {
    $this->actingAs($this->admin)->postJson($this->path.'/bill')->assertOk();
    $id = ($this->pay)(mk(50000), [['learner_id' => $this->alinafe, 'amount' => mk(50000)]], 'mpamba', 'TNM-77120')->assertCreated()->json('id');
    $clerk = feeStaff(ScopeRef::of(ScopeType::Institution, null), [FeesPermission::Collect, FeesPermission::View]);

    // The receipt as a document, with a check code anyone may confirm (D-096).
    $pdf = $this->actingAs($clerk)->get("/api/v1/fees/payments/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');
    $code = (string) DB::table('document_checks')->where('kind', 'fees.receipt')->value('code');
    expect($code)->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/');
    $this->actingAs($clerk)->get("/api/v1/fees/payments/{$id}/pdf")->assertOk();
    expect(DB::table('document_checks')->where('kind', 'fees.receipt')->count())->toBe(1);
    Auth::forgetGuards();
    $this->getJson('/api/v1/check/'.strtolower(str_replace('-', '', $code)))->assertOk()->assertJson(['kind' => 'Receipt', 'state' => 'genuine'])->assertJsonMissingPath('payer');
    $this->getJson('/api/v1/check/AAAA-BBBB-CCCC')->assertNotFound();

    $this->actingAs($clerk)->postJson("/api/v1/fees/payments/{$id}/cancel", ['reason' => 'Reversed by the provider.'])->assertForbidden();
    $this->actingAs($this->admin)->postJson("/api/v1/fees/payments/{$id}/cancel", ['reason' => ''])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("/api/v1/fees/payments/{$id}/cancel", ['reason' => 'Reversed by the provider.'])->assertOk()->assertJson(['number' => 'VOID-2026-000001']);
    $this->actingAs($this->admin)->postJson("/api/v1/fees/payments/{$id}/cancel", ['reason' => 'Again.'])->assertUnprocessable();
    expect($this->getJson("/api/v1/check/{$code}")->json('state'))->toBe('cancelled');
    $this->actingAs($this->admin)->get("/api/v1/learners/{$this->alinafe}")->assertOk();
    expect(substr((string) $this->actingAs($this->admin)->get("/api/v1/fees/learners/{$this->alinafe}/statement")->assertOk()->getContent(), 0, 4))->toBe('%PDF');

    $fees = $this->actingAs($this->admin)->getJson("/api/v1/fees/learners/{$this->alinafe}")->json();
    expect(array_column($fees['statement'], 'kind'))->toBe(['charge', 'payment', 'void'])
        ->and(end($fees['statement'])['balance'])->toBe(mk(165000))
        ->and($fees['standing']['paid'])->toBe(0)
        ->and($this->actingAs($this->admin)->getJson("/api/v1/fees/payments/{$id}")->json('void.number'))->toBe('VOID-2026-000001');

    // The ledger keeps the cancelled receipt, marked, and finds it by the learner's name.
    $ledger = $this->actingAs($clerk)->getJson('/api/v1/fees/payments/grid?q=alinafe')->assertOk()->json();
    expect($ledger['total'])->toBe(1)
        ->and($ledger['rows'][0]['cancelled'])->toBeTrue()
        ->and($ledger['rows'][0]['method_label'])->toBe('TNM Mpamba')
        ->and($this->actingAs($clerk)->getJson('/api/v1/fees/payments/grid?filter[state]=counted')->json('total'))->toBe(0);

    // Alinafe owes again: she is on the grid of learners behind their plan once the first instalment is due.
    $this->travelTo('2026-10-20 10:00:00');
    $behind = $this->actingAs($clerk)->getJson('/api/v1/fees/behind/grid?q=alinafe')->assertOk()->json();
    expect($behind['total'])->toBe(1)
        ->and($behind['rows'][0]['due_now'])->toBeGreaterThan(0)
        ->and($behind['bulk'])->toBe([['key' => 'remind', 'label' => 'Remind the families']]);

    // The ledger is the whole school's: a teacher of one class is refused.
    $teacher = feeStaff($this->school->scope(ScopeType::ClassGroup, 'class_primary_a'), [FeesPermission::View], 'Teacher');
    $this->actingAs($teacher)->getJson('/api/v1/fees/payments/grid')->assertForbidden();
});

it('agrees a different plan with a family, and gives a bursary that reduces the charge', function (): void {
    $this->actingAs($this->admin)->postJson($this->path.'/bill')->assertOk();
    $charge = (string) DB::table('fee_charges')->where('learner_id', $this->alinafe)->value('id');

    $this->actingAs($this->admin)->postJson("/api/v1/fees/charges/{$charge}/plan", ['reason' => 'Paid weekly from wages.',
        'instalments' => [['label' => 'Week 1', 'due_on' => '2026-09-14', 'amount' => mk(100000)], ['label' => 'Week 2', 'due_on' => '2026-09-21', 'amount' => mk(60000)]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['instalments']);
    $this->actingAs($this->admin)->postJson("/api/v1/fees/charges/{$charge}/plan", ['reason' => 'Paid weekly from wages.',
        'instalments' => [['label' => 'Week 1', 'due_on' => '2026-09-14', 'amount' => mk(100000)], ['label' => 'Week 2', 'due_on' => '2026-09-21', 'amount' => mk(65000)]]])->assertOk();

    $this->actingAs($this->admin)->postJson("/api/v1/fees/charges/{$charge}/adjust", ['kind' => 'bursary', 'amount' => mk(200000), 'reason' => 'Too much.'])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("/api/v1/fees/charges/{$charge}/adjust", ['kind' => 'bursary', 'amount' => mk(15000), 'reason' => 'Sibling discount.'])
        ->assertCreated()->assertJson(['number' => 'ADJ-2026-000001']);

    $fees = $this->actingAs($this->admin)->getJson("/api/v1/fees/learners/{$this->alinafe}")->json();
    expect(array_column($fees['standing']['instalments'], 'label'))->toBe(['Week 1', 'Week 2'])
        ->and(array_column($fees['standing']['instalments'], 'covered'))->toBe([mk(15000), 0])
        ->and($fees['standing']['left'])->toBe(mk(150000))
        ->and($fees['charges'][0]['agreed'])->toBeTrue()
        ->and(DB::table('fee_instalments')->where('charge_id', $charge)->whereNotNull('superseded_at')->count())->toBe(3);
});

it('sells from the school shop: paid now pays the sale, not older fees', function (): void {
    $this->actingAs($this->admin)->postJson($this->path.'/bill')->assertOk();
    $this->actingAs($this->admin)->putJson('/api/v1/fees/shop', ['items' => [['id' => null, 'name' => 'School shirt', 'size' => '14', 'price' => mk(8500), 'retired' => false]]])->assertOk();
    $shirt = (string) DB::table('fee_shop_items')->value('id');

    $sale = $this->actingAs($this->admin)->postJson('/api/v1/fees/shop/sale', ['learner_id' => $this->alinafe, 'lines' => [['item_id' => $shirt, 'quantity' => 2]],
        'pay_now' => 'cash', 'payer_name' => 'Esther Banda'])->assertCreated();
    expect($sale->json('payment_id'))->toBeString();

    $instalments = $this->actingAs($this->admin)->getJson("/api/v1/fees/learners/{$this->alinafe}")->json('standing.instalments');
    $shop = collect($instalments)->firstWhere('charge', 'School shop');
    expect($shop['state'])->toBe('paid')->and($shop['amount'])->toBe(mk(17000))
        ->and(collect($instalments)->firstWhere('label', 'On opening day')['covered'])->toBe(0);
});

it('shows the desk, closes the day, and takes no more payments on a closed day', function (): void {
    $this->actingAs($this->admin)->postJson($this->path.'/bill')->assertOk();
    ($this->pay)(mk(20000), [['learner_id' => $this->alinafe, 'amount' => mk(20000)]])->assertCreated();
    $this->travelTo('2026-10-10 09:00:00');
    ($this->pay)(mk(5000), [['learner_id' => $this->alinafe, 'amount' => mk(5000)]])->assertCreated();

    $desk = $this->actingAs($this->admin)->getJson('/api/v1/fees/desk')->assertOk()->json();
    expect($desk['billed'])->toBe(mk(330000))->and($desk['collected'])->toBe(mk(25000))->and($desk['behind_count'])->toBe(2)
        ->and($desk['takings'][0])->toMatchArray(['method' => 'cash', 'receipts' => 1, 'amount' => mk(5000)])
        ->and($desk['expected_cash'])->toBe(mk(5000));

    $this->actingAs($this->admin)->postJson('/api/v1/fees/reminders', ['learner_ids' => [$this->alinafe, $this->chikondi]])->assertOk()->assertJson(['sent' => 2]);
    $this->actingAs($this->admin)->postJson('/api/v1/fees/days/2026-10-10/close', ['counted' => mk(4500), 'note' => 'Short by 500.'])->assertOk()->assertJson(['difference' => -mk(500)]);
    $this->actingAs($this->admin)->postJson('/api/v1/fees/days/2026-10-10/close', ['counted' => mk(5000)])->assertUnprocessable();
    ($this->pay)(mk(1000), [['learner_id' => $this->alinafe, 'amount' => mk(1000)]])->assertUnprocessable()->assertJsonValidationErrors(['received_on']);

    $csv = $this->actingAs($this->admin)->get('/api/v1/fees/export?from=2026-09-01&to=2026-12-31')->assertOk()->streamedContent();
    expect($csv)->toContain('RCT-2026-000001')->toContain('Bill');
});

it('shows fees only where the person may see them, and takes payments only where they may receive them', function (): void {
    $this->actingAs($this->admin)->postJson($this->path.'/bill')->assertOk();
    $id = ($this->pay)(mk(20000), [['learner_id' => $this->alinafe, 'amount' => mk(20000)]])->assertCreated()->json('id');
    $teacher = feeStaff($this->school->scope(ScopeType::ClassGroup, 'class_primary_a'), [EnrolmentPermission::View, FeesPermission::View], 'Teacher');
    $elsewhere = feeStaff($this->school->scope(ScopeType::Campus, 'campus_b'), [EnrolmentPermission::View, FeesPermission::View, FeesPermission::Collect, FeesPermission::Adjust], 'Other campus');

    $this->actingAs($teacher)->getJson("/api/v1/fees/learners/{$this->alinafe}")->assertOk()->assertJsonPath('can.collect', false);
    $this->actingAs($teacher)->getJson("/api/v1/fees/payments/{$id}")->assertOk();
    $this->actingAs($teacher)->postJson('/api/v1/fees/payments', ['method' => 'cash', 'received_on' => '2026-09-10', 'payer_name' => 'X', 'amount' => 100,
        'shares' => [['learner_id' => $this->alinafe, 'amount' => 100]]])->assertForbidden();
    $this->actingAs($teacher)->getJson('/api/v1/fees/desk')->assertForbidden();

    $this->actingAs($elsewhere)->getJson("/api/v1/fees/learners/{$this->alinafe}")->assertNotFound();
    $this->actingAs($elsewhere)->getJson("/api/v1/fees/payments/{$id}")->assertNotFound();
    $this->actingAs($elsewhere)->postJson("/api/v1/fees/payments/{$id}/cancel", ['reason' => 'x'])->assertNotFound();
    $this->actingAs($elsewhere)->postJson('/api/v1/fees/payments', ['method' => 'cash', 'received_on' => '2026-09-10', 'payer_name' => 'X', 'amount' => 100,
        'shares' => [['learner_id' => $this->alinafe, 'amount' => 100]]])->assertNotFound();

    // The record shows what is due, as a part of the learner record.
    $record = $this->actingAs($this->admin)->getJson("/api/v1/learners/{$this->alinafe}")->assertOk();
    expect(array_column($record->json('parts'), 'key'))->toContain('fees');
});
