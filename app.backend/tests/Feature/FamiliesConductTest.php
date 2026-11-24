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
use Acadly\Modules\Attendance\Contracts\AttendancePermission;
use Acadly\Modules\Conduct\Contracts\ConductPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\TwoCampusSchool;

/*
 * Families II and the rest of conduct (D-110, D-111): a family sees the
 * conduct it was told about, the support plans shared with it and the
 * documents of the types the school shows; a suspension marks the register;
 * a learner with incidents and missed lessons in the same days is watched.
 */

uses(DatabaseTransactions::class);

/** @param  list<Permission>  $permissions */
function familiesStaff(ScopeRef $at, array $permissions, string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $role = Role::query()->create(['key' => 'fam_'.substr(Ids::next(), -12), 'name' => $name]);
    $role->grant($permissions);
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $at->type, 'scope_id' => $at->id, 'starts_at' => now()->subMinute()]);

    return $user;
}

function familiesPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
}

beforeEach(function (): void {
    $this->travelTo('2026-11-17 10:30:00');
    Storage::fake('documents');
    $this->artisan('acadly:sync')->assertSuccessful();
    foreach (['conduct', 'attendance', 'support'] as $module) {
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
        return (string) $this->actingAs($this->admin)->postJson('/api/v1/learners', [
            'first_name' => $first, 'other_names' => '', 'surname' => 'Mwale', 'sex' => 'female', 'date_of_birth' => '2019-05-02',
            'class_id' => $this->class, 'admitted_on' => '2026-09-07', 'admission_number' => '',
            'contacts' => [['name' => 'Ruth Mwale', 'relationship' => 'mother', 'phone' => $phone, 'email' => '', 'main' => true],
                ['name' => 'Peter Mwale', 'relationship' => 'father', 'phone' => '0999 120 334', 'email' => '', 'main' => false]],
        ])->assertCreated()->json('id');
    };
    $this->tiwonge = $admit('Tiwonge', '0884 771 901');
    $this->kondwani = $admit('Kondwani', '0884 771 902');

    $link = function (string $name, string $phone, string $learner): User {
        $user = User::factory()->create(['name' => $name, 'email' => null, 'phone' => $phone]);
        $guardian = Ids::next();
        DB::table('guardians')->insert(['id' => $guardian, 'phone' => $phone, 'name' => $name, 'user_id' => $user->id, 'created_at' => now()]);
        DB::table('learner_guardian_relationships')->insert(['id' => Ids::next(), 'guardian_id' => $guardian, 'learner_id' => $learner,
            'contact_id' => (string) DB::table('learner_contacts')->where('learner_id', $learner)->where('relationship', 'mother')->value('id'), 'held_by' => $user->id, 'state' => 'active',
            'journey_ref' => 'enrolment.guardian_link@2026.1', 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    };
    $this->ruth = $link('Ruth Mwale', '+265884771901', $this->tiwonge);
    $this->other = $link('Mary Phiri', '+265884771902', $this->kondwani);

    $this->teacher = familiesStaff($this->school->scope(ScopeType::ClassGroup, 'class_primary_a'),
        [EnrolmentPermission::View, ConductPermission::View, ConductPermission::Record, AttendancePermission::Capture], 'Peter Banda');
    $this->deputy = familiesStaff(ScopeRef::of(ScopeType::Institution, null), [EnrolmentPermission::View, ConductPermission::View, ConductPermission::FollowUp], 'Ruth Banda');
    $this->kind = fn (string $name): string => (string) DB::table('conduct_kinds')->where('name', $name)->value('id');
    $this->record = fn (string $kind, array $learners, array $more = []) => $this->actingAs($this->teacher)->postJson('/api/v1/conduct/entries', [
        'kind_id' => ($this->kind)($kind), 'learners' => $learners, 'happened_on' => '2026-11-17', 'what' => 'Hit Kondwani in the playground.', ...$more,
    ])->assertCreated();
    $this->suspend = function (): string {
        $case = (string) ($this->record)('Fighting', [$this->tiwonge, $this->kondwani])->json('case_id');
        $base = "/api/v1/conduct/incidents/{$case}";
        $this->actingAs($this->deputy)->postJson("{$base}/take-up")->assertOk();
        $this->actingAs($this->deputy)->postJson("{$base}/outcomes", ['learner_id' => $this->tiwonge, 'outcome' => 'suspension', 'starts_on' => '2026-11-18', 'days' => 3,
            'reason' => 'Started the fight.'])->assertCreated();
        $this->actingAs($this->deputy)->postJson("{$base}/outcomes", ['learner_id' => $this->kondwani, 'outcome' => 'warning', 'reason' => 'Hit back.'])->assertCreated();
        $this->actingAs($this->deputy)->postJson("{$base}/decide")->assertOk();

        return $case;
    };
});

it('marks a suspended learner Excused on each day of the suspension, naming the decision, until it is ended early', function (): void {
    $case = ($this->suspend)();
    $number = (string) DB::table('conduct_cases')->where('id', $case)->value('number');
    $url = "/api/v1/registers/class/{$this->class}";

    $this->travelTo('2026-11-18 08:00:00');
    $sheet = $this->actingAs($this->teacher)->getJson("{$url}?day=2026-11-18")->assertOk();
    $row = collect($sheet->json('learners'))->firstWhere('id', $this->tiwonge);
    expect($row['decided'])->toBe(['label' => 'Suspended', 'reference' => $number, 'from' => '2026-11-18', 'to' => '2026-11-20'])
        ->and(collect($sheet->json('learners'))->firstWhere('id', $this->kondwani)['decided'])->toBeNull();

    // Marked present by mistake: the decision sets the mark.
    $register = $this->actingAs($this->teacher)->postJson($url, ['day' => '2026-11-18', 'marks' => [
        ['learner_id' => $this->tiwonge, 'status' => 'present', 'note' => null], ['learner_id' => $this->kondwani, 'status' => 'present', 'note' => null],
    ]])->assertOk()->json('register.id');
    $mark = DB::table('attendance_marks')->where('learner_id', $this->tiwonge)->first(['status', 'note']);
    expect($mark?->status)->toBe('excused')->and($mark?->note)->toBe('Suspended · '.$number);

    // A correction cannot change it.
    $this->actingAs($this->admin)->postJson("/api/v1/registers/{$register}/corrections", ['learner_id' => $this->tiwonge, 'to' => 'present', 'reason' => 'Came in.'])
        ->assertUnprocessable();

    // The next day the deputy ends it early: today's register opens again.
    $this->travelTo('2026-11-19 08:00:00');
    $this->actingAs($this->teacher)->postJson("/api/v1/conduct/incidents/{$case}/learners/{$this->tiwonge}/end-suspension", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($this->deputy)->postJson("/api/v1/conduct/incidents/{$case}/learners/{$this->tiwonge}/end-suspension", ['reason' => ''])->assertUnprocessable();
    $this->actingAs($this->deputy)->postJson("/api/v1/conduct/incidents/{$case}/learners/{$this->kondwani}/end-suspension", ['reason' => 'x'])->assertUnprocessable();
    $this->actingAs($this->deputy)->postJson("/api/v1/conduct/incidents/{$case}/learners/{$this->tiwonge}/end-suspension", ['reason' => 'The family met the head.'])->assertOk();
    $this->actingAs($this->deputy)->postJson("/api/v1/conduct/incidents/{$case}/learners/{$this->tiwonge}/end-suspension", ['reason' => 'Again.'])->assertUnprocessable();

    expect(collect($this->actingAs($this->teacher)->getJson("{$url}?day=2026-11-19")->json('learners'))->firstWhere('id', $this->tiwonge)['decided'])->toBeNull()
        ->and(collect($this->actingAs($this->teacher)->getJson("{$url}?day=2026-11-18")->json('learners'))->firstWhere('id', $this->tiwonge)['decided']['to'])->toBe('2026-11-18');
    $outcome = collect($this->actingAs($this->deputy)->getJson("/api/v1/conduct/incidents/{$case}")->json('learners'))->firstWhere('id', $this->tiwonge)['outcome'];
    expect($outcome['ended_after'])->toBe('2026-11-18')->and($outcome['can_end'])->toBeFalse()
        ->and(DB::table('audit_events')->where('action', 'conduct.suspension_ended')->count())->toBe(1);
});

it('watches a learner with incidents and unexplained missed lessons in the same days', function (): void {
    ($this->record)('Late to class', [$this->tiwonge]);
    ($this->record)('Rudeness', [$this->tiwonge]);
    $lesson = function (string $day, string $status): void {
        $id = Ids::next();
        DB::table('attendance_registers')->insert(['id' => $id, 'class_id' => $this->class, 'day' => $day, 'kind' => 'lesson', 'submitted_by' => $this->teacher->id,
            'submitted_at' => now(), 'slot_id' => Ids::next(), 'period' => 1, 'starts' => '08:00', 'ends' => '08:40']);
        DB::table('attendance_marks')->insert(['id' => Ids::next(), 'register_id' => $id, 'learner_id' => $this->tiwonge, 'status' => $status, 'method' => 'manual',
            'captured_by' => $this->teacher->id, 'captured_at' => now(), 'received_at' => now()]);
    };
    foreach (['2026-11-10', '2026-11-12', '2026-11-16', '2026-11-17'] as $day) {
        $lesson($day, 'absent');
    }
    // Excused, and too long ago: neither counts.
    $lesson('2026-11-16', 'excused');
    $lesson('2026-10-20', 'absent');

    $watch = fn () => $this->actingAs($this->teacher)->getJson("/api/v1/conduct/learners/{$this->tiwonge}")->assertOk()->json('watch');
    expect($watch())->toBe(['2 incidents and 4 unexplained missed lessons in 14 days.']);

    $rules = ['incidents' => 3, 'days' => 30, 'open_serious' => true, 'missed_incidents' => 2, 'missed_lessons' => 5, 'missed_days' => 14, 'portal' => true];
    $this->actingAs($this->teacher)->putJson('/api/v1/conduct/watch', $rules)->assertForbidden();
    $this->actingAs($this->admin)->putJson('/api/v1/conduct/watch', [...$rules, 'missed_lessons' => 0])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/v1/conduct/watch', $rules)->assertOk();
    expect($watch())->toBe([]);
    $this->actingAs($this->admin)->putJson('/api/v1/conduct/watch', [...$rules, 'missed_lessons' => 4, 'missed_incidents' => 0])->assertOk();
    expect($watch())->toBe([]);
});

it('shows a family only the conduct it was told about, never the text staff wrote', function (): void {
    ($this->record)('Helping others', [$this->tiwonge]);
    ($this->record)('Late to class', [$this->tiwonge]);
    $withdrawn = ($this->record)('Rudeness', [$this->tiwonge], ['tell' => true])->json('entry_id');
    $this->actingAs($this->teacher)->postJson("/api/v1/conduct/entries/{$withdrawn}/withdraw", ['reason' => 'Wrong learner.'])->assertOk();
    $case = ($this->suspend)();
    Auth::forgetGuards();

    $seen = $this->actingAs($this->ruth)->getJson("/api/v1/portal/children/{$this->tiwonge}/conduct")->assertOk();
    expect(array_column($seen->json('entries'), 'kind'))->toEqualCanonicalizing(['Helping others', 'Fighting'])
        ->and($seen->json('commendations'))->toBe(1)
        ->and($seen->json('incidents'))->toBe(1)
        ->and($seen->json('serious.0'))->toMatchArray(['kind' => 'Fighting', 'stage' => 'Decided', 'decision' => 'Suspension: 3 school days from 18 Nov', 'meeting' => true])
        ->and((string) $seen->getContent())->not->toContain('playground')->not->toContain('Started the fight')->not->toContain('Kondwani');

    // The door on the child's screen.
    $lines = collect($this->actingAs($this->ruth)->getJson('/api/v1/portal/family')->assertOk()->json('children'))->firstWhere('id', $this->tiwonge)['lines'];
    expect(collect($lines)->firstWhere('key', 'conduct'))->toMatchArray(['word' => 'conduct', 'label' => '1 1']);

    // Another family's child, and conduct not shown to families: not found.
    $this->actingAs($this->other)->getJson("/api/v1/portal/children/{$this->tiwonge}/conduct")->assertNotFound();
    DB::table('conduct_watch')->update(['portal' => false]);
    $this->actingAs($this->ruth)->getJson("/api/v1/portal/children/{$this->tiwonge}/conduct")->assertNotFound();
    expect($case)->not->toBe('');
});

it('shares the part of a support plan written for the family, never a confidential plan', function (): void {
    $plan = $this->actingAs($this->admin)->postJson('/api/v1/support/plans', ['learner_id' => $this->tiwonge, 'title' => 'Reading support', 'concern' => 'Reads two years behind.'])
        ->assertCreated()->json('id');
    $family = "/api/v1/support/plans/{$plan}/family";
    $this->actingAs($this->ruth)->getJson("/api/v1/portal/children/{$this->tiwonge}/support")->assertNotFound();

    $this->actingAs($this->admin)->putJson($family, ['school' => 'Reads with a teacher three mornings a week.', 'home' => '', 'shown' => true])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson($family, ['school' => 'Reads with a teacher three mornings a week.', 'home' => 'Listen to her read most evenings.', 'shown' => true])->assertOk();
    expect($this->actingAs($this->admin)->getJson("/api/v1/support/plans/{$plan}")->json('family'))
        ->toBe(['school' => 'Reads with a teacher three mornings a week.', 'home' => 'Listen to her read most evenings.', 'shown' => true]);
    Auth::forgetGuards();

    $seen = $this->actingAs($this->ruth)->getJson("/api/v1/portal/children/{$this->tiwonge}/support")->assertOk();
    expect($seen->json('plans.0'))->toMatchArray(['title' => 'Reading support', 'lead' => 'Grace Phiri', 'home' => 'Listen to her read most evenings.'])
        ->and((string) $seen->getContent())->not->toContain('two years behind');
    $lines = collect($this->actingAs($this->ruth)->getJson('/api/v1/portal/family')->json('children'))->firstWhere('id', $this->tiwonge)['lines'];
    expect(collect($lines)->firstWhere('key', 'plan')['label'])->toEndWith(' Reading support');
    $this->actingAs($this->other)->getJson("/api/v1/portal/children/{$this->tiwonge}/support")->assertNotFound();

    // A confidential plan cannot be shown.
    $secret = $this->actingAs($this->admin)->postJson('/api/v1/support/plans', ['learner_id' => $this->kondwani, 'title' => 'Counselling', 'concern' => 'x', 'confidential' => true])
        ->assertCreated()->json('id');
    $this->actingAs($this->admin)->putJson("/api/v1/support/plans/{$secret}/family", ['school' => 'a', 'home' => 'b', 'shown' => true])->assertUnprocessable();
});

it('shows a family the documents of the types the school ticks, records each opening, and never an office type', function (): void {
    $type = fn (string $name): string => (string) DB::table('learner_document_types')->where('name', $name)->value('id');
    foreach (['Previous school reports', 'Birth certificate'] as $name) {
        $this->actingAs($this->admin)->post("/api/v1/learners/{$this->tiwonge}/documents", ['type_id' => $type($name), 'files' => [familiesPdf()]],
            ['Accept' => 'application/json'])->assertCreated();
    }
    $portal = "/api/v1/portal/children/{$this->tiwonge}/documents";
    $this->actingAs($this->ruth)->getJson($portal)->assertNotFound();

    $this->actingAs($this->admin)->putJson('/api/v1/learner-document-types/'.$type('Previous school reports'), ['name' => 'Previous school reports', 'access' => 'staff', 'expected' => false, 'families' => true])->assertOk();
    // An office type is never shown, even when asked.
    $this->actingAs($this->admin)->putJson('/api/v1/learner-document-types/'.$type('Birth certificate'), ['name' => 'Birth certificate', 'access' => 'office', 'expected' => true, 'families' => true])->assertOk();
    expect((bool) DB::table('learner_document_types')->where('name', 'Birth certificate')->value('families'))->toBeFalse();
    Auth::forgetGuards();

    $list = $this->actingAs($this->ruth)->getJson($portal)->assertOk();
    expect(array_column($list->json('documents'), 'type'))->toBe(['Previous school reports'])->and($list->json('documents.0.file_type'))->toBe('pdf');
    $doc = $list->json('documents.0.id');
    $office = (string) DB::table('learner_documents')->where('type_id', $type('Birth certificate'))->value('document_id');

    $this->actingAs($this->ruth)->get("{$portal}/{$doc}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->ruth)->get("{$portal}/{$office}")->assertNotFound();
    $this->actingAs($this->other)->get("{$portal}/{$doc}")->assertNotFound();
    expect(DB::table('audit_events')->where('action', 'documents.read')->where('actor_user_id', $this->ruth->id)->count())->toBe(1);

    $lines = collect($this->actingAs($this->ruth)->getJson('/api/v1/portal/family')->json('children'))->firstWhere('id', $this->tiwonge)['lines'];
    expect(collect($lines)->firstWhere('key', 'documents')['label'])->toBe('1');

    // Unticked: hidden again at once.
    DB::table('learner_document_types')->update(['families' => false]);
    $this->actingAs($this->ruth)->getJson($portal)->assertNotFound();
    $this->actingAs($this->ruth)->get("{$portal}/{$doc}")->assertNotFound();
});
