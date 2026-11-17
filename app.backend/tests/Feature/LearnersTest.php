<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Application\AcadlyId;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    app()->forgetScopedInstances();
    Storage::fake('documents');
    $this->school = TwoCampusSchool::make();
    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    // A second Standard 1 class at campus A, for class changes.
    $this->eastId = Ids::next();
    DB::table('academic_classes')->insert(['id' => $this->eastId, 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id('primary'),
        'campus_id' => $this->school->id('campus_a'), 'level_code' => 'STD1', 'stream' => 'East', 'name' => 'STD1 East', 'position' => 2,
        'created_at' => now(), 'updated_at' => now()]);
});

/** @param  array<string, mixed>  $overrides */
function admission(string $classId, array $overrides = []): array
{
    return [
        'first_name' => 'Kumbukani', 'other_names' => '', 'surname' => 'Jere', 'sex' => 'male', 'date_of_birth' => '2019-05-02',
        'class_id' => $classId, 'admitted_on' => '2026-09-08', 'admission_number' => '',
        'contacts' => [
            ['name' => 'Alice Jere', 'relationship' => 'mother', 'phone' => '0884 771 900', 'email' => 'alice@example.mw', 'main' => true],
            ['name' => 'Daniel Jere', 'relationship' => 'father', 'phone' => '0999 120 334', 'email' => '', 'main' => false],
        ],
        ...$overrides,
    ];
}

function learnerRole(string $name, array $permissions): Role
{
    $role = Role::query()->create(['key' => 'l_'.substr(Ids::next(), -10), 'name' => $name]);
    DB::table('access_role_permissions')->insert(array_map(fn (EnrolmentPermission $p): array => ['role_id' => $role->id, 'permission_key' => $p->value], $permissions));

    return $role;
}

function staffAt(ScopeRef $at, array $permissions, string $name = 'Mercy Banda'): User
{
    $user = User::factory()->create(['name' => $name]);
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => learnerRole($name.' role', $permissions)->id,
        'scope_type' => $at->type, 'scope_id' => $at->id, 'starts_at' => now()->subMinute()]);

    return $user;
}

function learnerPng(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('face.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
}

describe('admitting', function (): void {
    it('admits a learner with an Acadly ID, the next admission number, a placement and two contacts', function (): void {
        $class = $this->school->id('class_primary_a');
        $id = $this->actingAs($this->admin)->postJson('/api/v1/learners', admission($class, ['admission_number' => 'sa-2026-0414']))->assertCreated()->json('id');
        $second = $this->actingAs($this->admin)->postJson('/api/v1/learners', admission($class, ['first_name' => 'Thoko', 'surname' => 'Chisale']))->assertCreated()->json('id');

        $record = $this->actingAs($this->admin)->getJson("/api/v1/learners/{$id}")->assertOk()->json();

        expect(AcadlyId::isValid($record['acadly_id']))->toBeTrue()
            ->and($record['admission_number'])->toBe('SA-2026-0414')
            ->and($record['word'])->toBe('Pupil')
            ->and($record['class']['id'])->toBe($class)
            ->and($record['placements'])->toHaveCount(1)
            ->and($record['placements'][0])->toMatchArray(['entry_reason' => 'admission', 'starts_on' => '2026-09-08', 'now' => true])
            ->and($record['contacts'])->toHaveCount(2)
            ->and($record['contacts'][0])->toMatchArray(['name' => 'Alice Jere', 'main' => true, 'relationship_label' => 'Mother'])
            ->and($record['activity'][0]['what'])->toBe('Admitted a learner into a class.')
            ->and($this->actingAs($this->admin)->getJson("/api/v1/learners/{$second}")->json('admission_number'))->toBe('SA-2026-0415');

        $list = $this->actingAs($this->admin)->getJson('/api/v1/learners?class_id='.$class)->assertOk()->json();

        expect(collect($list['learners'])->pluck('name')->all())->toBe(['Thoko Chisale', 'Kumbukani Jere'])
            ->and($list['totals'])->toMatchArray(['active' => 2, 'few_contacts' => 0, 'duplicates' => 0])
            ->and(collect($list['classes'])->firstWhere('id', $class)['count'])->toBe(2);
    });

    it('refuses fewer than two contacts, a number already issued, and a date in the future', function (): void {
        $class = $this->school->id('class_primary_a');
        $one = admission($class);
        $one['contacts'] = [$one['contacts'][0]];

        $this->actingAs($this->admin)->postJson('/api/v1/learners', $one)->assertUnprocessable()->assertJsonValidationErrors(['contacts']);
        $this->actingAs($this->admin)->postJson('/api/v1/learners', admission($class, ['admission_number' => 'A-1']))->assertCreated();
        $this->actingAs($this->admin)->postJson('/api/v1/learners', admission($class, ['first_name' => 'Wezi', 'admission_number' => 'a-1']))
            ->assertUnprocessable()->assertJsonValidationErrors(['admission_number']);
        $this->actingAs($this->admin)->postJson('/api/v1/learners', admission($class, ['first_name' => 'Wezi', 'admitted_on' => now()->addDays(3)->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors(['admitted_on']);
    });

    it('holds a possible duplicate until the person confirms a different learner, naming it only to someone who may see it', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/learners', admission($this->school->id('class_primary_b')))->assertCreated();
        $office = staffAt($this->school->scope(ScopeType::Campus, 'campus_a'), [EnrolmentPermission::View, EnrolmentPermission::Admit]);

        $this->actingAs($this->admin)->postJson('/api/v1/learners', admission($this->school->id('class_primary_a'), ['surname' => 'JERE']))
            ->assertUnprocessable()->assertJsonValidationErrors(['duplicate', 'duplicate_id']);
        $hidden = $this->actingAs($office)->postJson('/api/v1/learners', admission($this->school->id('class_primary_a')))->assertUnprocessable()->json('errors');

        expect($hidden)->toHaveKey('duplicate')->not->toHaveKey('duplicate_id')
            ->and($hidden['duplicate'][0])->not->toContain('Kumbukani');

        $id = $this->actingAs($office)->postJson('/api/v1/learners', admission($this->school->id('class_primary_a'), ['different' => true]))->assertCreated()->json('id');

        expect($this->actingAs($office)->getJson("/api/v1/learners/{$id}")->json('possible_duplicate'))->toBeTrue();
    });
});

describe('the record', function (): void {
    it('moves a learner to another class of the same level and campus, keeping the history', function (): void {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/learners', admission($this->school->id('class_primary_a')))->json('id');

        $this->actingAs($this->admin)->postJson("/api/v1/learners/{$id}/class", ['class_id' => $this->school->id('class_primary_b'), 'from' => '2026-10-01', 'reason' => 'Moved house.'])
            ->assertUnprocessable()->assertJsonValidationErrors(['class_id']);
        $this->actingAs($this->admin)->postJson("/api/v1/learners/{$id}/class", ['class_id' => $this->eastId, 'from' => '2026-09-08', 'reason' => 'Streams.'])
            ->assertUnprocessable()->assertJsonValidationErrors(['from']);
        $this->actingAs($this->admin)->postJson("/api/v1/learners/{$id}/class", ['class_id' => $this->eastId, 'from' => '2026-10-01', 'reason' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);

        $record = $this->actingAs($this->admin)->postJson("/api/v1/learners/{$id}/class", ['class_id' => $this->eastId, 'from' => '2026-10-01', 'reason' => 'The streams were balanced.'])
            ->assertOk()->json();

        expect($record['class']['id'])->toBe($this->eastId)
            ->and($record['placements'])->toHaveCount(2)
            ->and($record['placements'][0])->toMatchArray(['class' => 'STD1 East', 'starts_on' => '2026-10-01', 'entry_reason' => 'class_change', 'now' => true])
            ->and($record['placements'][1])->toMatchArray(['ends_on' => '2026-09-30', 'now' => false])
            ->and(DB::table('placements')->where('learner_id', $id)->whereNull('ends_on')->count())->toBe(1);
    });

    it('keeps at least two contacts and moves the main contact on when it is removed', function (): void {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/learners', admission($this->school->id('class_primary_a')))->json('id');
        $main = $this->actingAs($this->admin)->getJson("/api/v1/learners/{$id}")->json('contacts.0.id');

        $this->actingAs($this->admin)->postJson("/api/v1/learners/{$id}/contacts/{$main}/remove", ['reason' => 'Moved abroad.'])
            ->assertUnprocessable()->assertJsonValidationErrors(['contact']);
        $this->actingAs($this->admin)->postJson("/api/v1/learners/{$id}/contacts", ['name' => 'Esther Jere', 'relationship' => 'grandparent', 'phone' => '0995 002 118'])
            ->assertOk();
        $record = $this->actingAs($this->admin)->postJson("/api/v1/learners/{$id}/contacts/{$main}/remove", ['reason' => 'Moved abroad.'])->assertOk()->json();

        expect($record['contacts'])->toHaveCount(2)
            ->and($record['contacts'][0])->toMatchArray(['name' => 'Daniel Jere', 'main' => true])
            ->and(DB::table('learner_contacts')->where('learner_id', $id)->count())->toBe(3);
    });

    it('corrects details with a reason, and never issues a corrected number again', function (): void {
        $class = $this->school->id('class_primary_a');
        $id = $this->actingAs($this->admin)->postJson('/api/v1/learners', admission($class, ['admission_number' => 'X-10']))->json('id');
        $details = ['first_name' => 'Kumbukani', 'other_names' => 'Peter', 'surname' => 'Jere', 'sex' => 'male', 'date_of_birth' => '2019-05-03', 'admission_number' => 'X-11'];

        $this->actingAs($this->admin)->putJson("/api/v1/learners/{$id}", $details)->assertUnprocessable()->assertJsonValidationErrors(['reason']);

        // A new name, date of birth or sex is asked for first; the details in force stay until the last yes.
        $officer = staffAt(ScopeRef::institution(), [EnrolmentPermission::View, EnrolmentPermission::Manage], 'Ruth Tembo');
        $asked = $this->actingAs($this->admin)->putJson("/api/v1/learners/{$id}", [...$details, 'reason' => 'Birth certificate seen.'])->assertOk()->json();
        expect($asked)->toMatchArray(['name' => 'Kumbukani Jere', 'admission_number' => 'X-10', 'details_asks' => true])
            ->and($asked['details_request'])->toMatchArray(['reason' => 'Birth certificate seen.', 'waiting_for' => ['Ruth Tembo'], 'mine' => true])
            ->and(array_column($asked['details_request']['changes'], 'label'))->toContain('Other names');
        $this->actingAs($this->admin)->putJson("/api/v1/learners/{$id}", [...$details, 'reason' => 'Again.'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'A correction of these details is already waiting for approval.']);
        $request = DB::table('approval_requests')->where('topic_key', 'enrolment.details')->where('state', 'waiting')->value('id');
        $this->actingAs($officer)->postJson("/api/v1/approvals/{$request}/answer", ['approve' => true])->assertOk();
        $record = $this->actingAs($this->admin)->getJson("/api/v1/learners/{$id}")->assertOk()->json();

        expect($record)->toMatchArray(['name' => 'Kumbukani Peter Jere', 'date_of_birth' => '2019-05-03', 'admission_number' => 'X-11', 'details_request' => null])
            ->and(DB::table('audit_events')->where('action', 'enrolment.details_corrected')->value('reason'))->toBe('Birth certificate seen.');

        $this->actingAs($this->admin)->postJson('/api/v1/learners', admission($class, ['first_name' => 'Wezi', 'admission_number' => 'X-10']))
            ->assertUnprocessable()->assertJsonValidationErrors(['admission_number']);
    });

    it('keeps an optional photo as a document of the learner, opened only by those who may see them', function (): void {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/learners', admission($this->school->id('class_primary_a')))->json('id');
        $elsewhere = staffAt($this->school->scope(ScopeType::Campus, 'campus_b'), [EnrolmentPermission::View, EnrolmentPermission::Manage]);

        expect($this->actingAs($this->admin)->getJson("/api/v1/learners/{$id}")->json('has_photo'))->toBeFalse();

        $this->actingAs($this->admin)->post("/api/v1/learners/{$id}/photo", ['file' => learnerPng()], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('has_photo', true);
        $this->actingAs($this->admin)->get("/api/v1/learners/{$id}/photo")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($elsewhere)->get("/api/v1/learners/{$id}/photo", ['Accept' => 'application/json'])->assertNotFound();
        $this->actingAs($elsewhere)->post("/api/v1/learners/{$id}/photo", ['file' => learnerPng()], ['Accept' => 'application/json'])->assertNotFound();

        expect(DB::table('audit_events')->where('action', 'documents.read')->exists())->toBeTrue();
    });
});

describe('who sees learners', function (): void {
    it('shows staff only the learners of the classes where they hold See learners', function (): void {
        $a = $this->actingAs($this->admin)->postJson('/api/v1/learners', admission($this->school->id('class_primary_a')))->json('id');
        $b = $this->actingAs($this->admin)->postJson('/api/v1/learners', admission($this->school->id('class_primary_b'), ['first_name' => 'Thoko', 'surname' => 'Chisale']))->json('id');
        $teacher = staffAt($this->school->scope(ScopeType::ClassGroup, 'class_primary_b'), [EnrolmentPermission::View], 'Teacher at B');
        $nobody = User::factory()->create();

        $list = $this->actingAs($teacher)->getJson('/api/v1/learners')->assertOk()->json();

        expect(collect($list['learners'])->pluck('id')->all())->toBe([$b])
            ->and($list['can_admit'])->toBeFalse()
            ->and($list['totals']['active'])->toBe(1);

        $this->actingAs($teacher)->getJson("/api/v1/learners/{$a}")->assertNotFound();
        $this->actingAs($teacher)->getJson("/api/v1/learners/{$b}")->assertOk()->assertJsonPath('can_manage', false);
        $this->actingAs($teacher)->postJson("/api/v1/learners/{$b}/class", ['class_id' => $this->eastId, 'from' => '2026-10-01', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($teacher)->postJson('/api/v1/learners', admission($this->school->id('class_primary_b'), ['first_name' => 'Wezi']))->assertForbidden();
        $this->actingAs($nobody)->getJson('/api/v1/learners')->assertForbidden();
    });
});
