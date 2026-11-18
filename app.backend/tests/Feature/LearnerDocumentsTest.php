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
    $this->learner = $this->actingAs($this->admin)->postJson('/api/v1/learners', [
        'first_name' => 'Mphatso', 'other_names' => '', 'surname' => 'Chirwa', 'sex' => 'female', 'date_of_birth' => '2019-05-02',
        'class_id' => $this->school->id('class_primary_a'), 'admitted_on' => '2026-09-08', 'admission_number' => '',
        'contacts' => [['name' => 'Esther Chirwa', 'relationship' => 'mother', 'phone' => '0884 771 900', 'email' => '', 'main' => true],
            ['name' => 'Daniel Chirwa', 'relationship' => 'father', 'phone' => '0999 120 334', 'email' => '', 'main' => false]],
    ]);
    expect($this->learner->status())->toBe(201, (string) $this->learner->getContent());
    $this->learner = $this->learner->json('id');
    $this->type = fn (string $name): string => (string) DB::table('learner_document_types')->where('name', $name)->value('id');
});

/** @param  list<Permission>  $permissions */
function recordStaff(ScopeRef $at, array $permissions, string $name = 'Staff'): User
{
    $user = User::factory()->create(['name' => $name]);
    $role = Role::query()->create(['key' => 'rec_'.substr(Ids::next(), -12), 'name' => $name]);
    $role->grant($permissions);
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $at->type,
        'scope_id' => $at->id, 'starts_at' => now()->subMinute()]);

    return $user;
}

function scanPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('certificate.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
}

function pagePhoto(string $name = 'page.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 600, 840);
}

it('keeps a document of a school type on the record, with its picture, and says what is still expected', function (): void {
    $before = $this->actingAs($this->admin)->getJson("/api/v1/learners/{$this->learner}/documents")->assertOk();
    expect(array_column($before->json('missing'), 'name'))->toBe(['Birth certificate'])
        ->and($before->json('can_add'))->toBe(['staff', 'office', 'confidential'])
        ->and(count($before->json('types')))->toBe(9);

    $this->actingAs($this->admin)->post("/api/v1/learners/{$this->learner}/documents", [
        'type_id' => ($this->type)('Birth certificate'), 'title' => '', 'date' => '2018-03-20', 'files' => [scanPdf()], 'preview' => pagePhoto('preview.png'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $after = $this->actingAs($this->admin)->getJson("/api/v1/learners/{$this->learner}/documents")->assertOk();
    $doc = $after->json('documents.0');
    expect($after->json('missing'))->toBe([])
        ->and($doc['title'])->toBe('Birth certificate')
        ->and($doc['access'])->toBe('office')
        ->and($doc['date'])->toBe('2018-03-20')
        ->and($doc['file_type'])->toBe('pdf')
        ->and($doc['thumbnail'])->toBeTrue()
        ->and($doc['added_by'])->toBe('Grace Phiri');

    $this->actingAs($this->admin)->get("/api/v1/documents/{$doc['id']}/thumbnail")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
});

it('shows a teacher only the documents of types open to staff, and lets them add none', function (): void {
    foreach (['Birth certificate', 'Previous school reports'] as $name) {
        $this->actingAs($this->admin)->post("/api/v1/learners/{$this->learner}/documents", ['type_id' => ($this->type)($name), 'files' => [scanPdf()]],
            ['Accept' => 'application/json'])->assertCreated();
    }

    $teacher = recordStaff($this->school->scope(ScopeType::ClassGroup, 'class_primary_a'), [EnrolmentPermission::View], 'Teacher');
    $list = $this->actingAs($teacher)->getJson("/api/v1/learners/{$this->learner}/documents")->assertOk();
    $office = DB::table('learner_documents')->where('type_id', ($this->type)('Birth certificate'))->value('document_id');

    expect(array_column($list->json('documents'), 'title'))->toBe(['Previous school reports'])
        ->and($list->json('can_add'))->toBe([]);
    $this->actingAs($teacher)->get("/api/v1/documents/{$office}/content", ['Accept' => 'application/json'])->assertNotFound();
    $this->actingAs($teacher)->post("/api/v1/learners/{$this->learner}/documents", ['type_id' => ($this->type)('Previous school reports'), 'files' => [scanPdf()]],
        ['Accept' => 'application/json'])->assertForbidden();

    // Someone at the other campus does not learn that the learner exists.
    $elsewhere = recordStaff($this->school->scope(ScopeType::Campus, 'campus_b'), [EnrolmentPermission::View, EnrolmentPermission::Manage]);
    $this->actingAs($elsewhere)->getJson("/api/v1/learners/{$this->learner}/documents")->assertNotFound();
});

it('opens a confidential document only with the confidential permission, records each opening, and shows no picture of it', function (): void {
    $at = $this->school->scope(ScopeType::ClassGroup, 'class_primary_a');
    $nurse = recordStaff($at, [EnrolmentPermission::View, EnrolmentPermission::Confidential], 'Nurse');
    $office = recordStaff($at, [EnrolmentPermission::View, EnrolmentPermission::Manage], 'Office');

    $id = $this->actingAs($nurse)->post("/api/v1/learners/{$this->learner}/documents", ['type_id' => ($this->type)('Medical'), 'title' => 'Asthma care plan', 'files' => [pagePhoto()]],
        ['Accept' => 'application/json'])->assertCreated()->json('id');

    expect(array_column($this->actingAs($office)->getJson("/api/v1/learners/{$this->learner}/documents")->json('documents'), 'id'))->toBe([])
        ->and($this->actingAs($nurse)->getJson("/api/v1/learners/{$this->learner}/documents")->json('documents.0.thumbnail'))->toBeFalse();
    $this->actingAs($office)->get("/api/v1/documents/{$id}/content", ['Accept' => 'application/json'])->assertNotFound();
    $this->actingAs($nurse)->get("/api/v1/documents/{$id}/thumbnail", ['Accept' => 'application/json'])->assertNotFound();
    $this->actingAs($nurse)->get("/api/v1/documents/{$id}/content")->assertOk();

    expect(DB::table('audit_events')->where('action', 'documents.read')->where('subject_id', $id)->exists())->toBeTrue();
});

it('keeps the photos of a paper document as the pages of one PDF, with a picture of the first', function (): void {
    $id = $this->actingAs($this->admin)->post("/api/v1/learners/{$this->learner}/documents", [
        'type_id' => ($this->type)('Transfer letter'), 'title' => 'Transfer letter from Chilomoni Primary', 'files' => [pagePhoto('1.jpg'), pagePhoto('2.jpg')],
    ], ['Accept' => 'application/json'])->assertCreated()->json('id');

    $pdf = $this->actingAs($this->admin)->get("/api/v1/documents/{$id}/content")->assertOk()->assertHeader('Content-Type', 'application/pdf')->streamedContent();
    expect($pdf)->toStartWith('%PDF-1.4')->toContain('/Count 2')
        ->and($this->actingAs($this->admin)->getJson("/api/v1/learners/{$this->learner}/documents")->json('documents.0.thumbnail'))->toBeTrue();

    $this->actingAs($this->admin)->post("/api/v1/learners/{$this->learner}/documents", ['type_id' => ($this->type)('Transfer letter'), 'files' => [pagePhoto(), scanPdf()]],
        ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['files']);
});

it('draws the picture of a single photo once it is kept', function (): void {
    $this->actingAs($this->admin)->post("/api/v1/learners/{$this->learner}/documents", ['type_id' => ($this->type)('Consent forms'), 'files' => [pagePhoto()]],
        ['Accept' => 'application/json'])->assertCreated();

    expect($this->actingAs($this->admin)->getJson("/api/v1/learners/{$this->learner}/documents")->json('documents.0.thumbnail'))->toBeTrue();
});

it('lets the office keep the list of types; a change of who may open a type moves its documents', function (): void {
    $at = $this->school->scope(ScopeType::ClassGroup, 'class_primary_a');
    $office = recordStaff($at, [EnrolmentPermission::View, EnrolmentPermission::Manage], 'Office');
    $this->actingAs($this->admin)->post("/api/v1/learners/{$this->learner}/documents", ['type_id' => ($this->type)('Transfer letter'), 'files' => [scanPdf()]],
        ['Accept' => 'application/json'])->assertCreated();

    $this->actingAs($office)->getJson('/api/v1/learner-document-types')->assertForbidden();
    $types = $this->actingAs($this->admin)->getJson('/api/v1/learner-document-types')->assertOk();
    expect(collect($types->json('types'))->firstWhere('name', 'Transfer letter')['on_file'])->toBe(1);

    $this->actingAs($this->admin)->postJson('/api/v1/learner-document-types', ['name' => 'Fee clearance letter', 'access' => 'office', 'expected' => true])->assertCreated();
    $this->actingAs($this->admin)->postJson('/api/v1/learner-document-types', ['name' => 'fee clearance LETTER', 'access' => 'staff', 'expected' => false])
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);

    expect(array_column($this->actingAs($office)->getJson("/api/v1/learners/{$this->learner}/documents")->json('missing'), 'name'))->toBe(['Birth certificate', 'Fee clearance letter'])
        ->and($this->actingAs($office)->getJson("/api/v1/learners/{$this->learner}/documents")->json('documents'))->toHaveCount(1);

    $this->actingAs($this->admin)->putJson('/api/v1/learner-document-types/'.($this->type)('Transfer letter'), ['name' => 'Transfer letter', 'access' => 'confidential', 'expected' => false])->assertOk();
    expect($this->actingAs($office)->getJson("/api/v1/learners/{$this->learner}/documents")->json('documents'))->toHaveCount(0);

    $this->actingAs($this->admin)->postJson('/api/v1/learner-document-types/'.($this->type)('Other').'/retire')->assertOk();
    $this->actingAs($this->admin)->post("/api/v1/learners/{$this->learner}/documents", ['type_id' => ($this->type)('Other'), 'files' => [scanPdf()]],
        ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['type_id']);
});
