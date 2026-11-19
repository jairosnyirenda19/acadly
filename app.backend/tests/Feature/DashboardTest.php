<?php

declare(strict_types=1);

use Acadly\Core\Institution\Contracts\InstitutionPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

/*
 * D-100. The dashboards and Today: the whole-school overview, each division's
 * own dashboard, and a person's Today, each kept to the place worked in.
 */

beforeEach(function (): void {
    $this->travelTo('2026-11-19 10:30:00');
    $this->artisan('acadly:sync')->assertSuccessful();
    foreach (['attendance', 'assessment', 'fees', 'conduct', 'support'] as $module) {
        DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => $module, 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    }
    app()->forgetScopedInstances();
    $this->school = TwoCampusSchool::make();
    foreach (['primary', 'secondary'] as $d) {
        DB::table('academic_periods')->insert(['id' => Ids::next(), 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id($d),
            'name' => 'Term 1', 'position' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->owner = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->owner->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMonths(6)]);
    $this->inPlace('division', $this->school->id('primary'), $this->owner);

    $admit = function (string $first, string $class): string {
        $this->inPlace('division', $this->school->id(str_contains($class, 'secondary') ? 'secondary' : 'primary'), $this->owner);
        $response = $this->actingAs($this->owner)->postJson('/api/v1/learners', [
            'first_name' => $first, 'other_names' => '', 'surname' => 'Mvula', 'sex' => 'female', 'date_of_birth' => '2015-05-02',
            'class_id' => $this->school->id($class), 'admitted_on' => '2026-09-07', 'admission_number' => '',
            'contacts' => [['name' => 'Esther Mvula', 'relationship' => 'mother', 'phone' => '0884 771 9'.random_int(10, 99), 'email' => '', 'main' => true],
                ['name' => 'Daniel Mvula', 'relationship' => 'father', 'phone' => '0999 120 3'.random_int(10, 99), 'email' => '', 'main' => false]],
        ]);
        expect($response->status())->toBe(201, (string) $response->getContent());

        return (string) $response->json('id');
    };
    $this->primary = [$admit('Alinafe', 'class_primary_a'), $admit('Chikondi', 'class_primary_a'), $admit('Dalitso', 'class_primary_b')];
    $this->secondary = [$admit('Quokkaline', 'class_secondary_b')];

    // Morning registers for the last three weeks: one Primary learner is often away.
    $register = function (string $class, string $day, array $marks): void {
        $id = Ids::next();
        DB::table('attendance_registers')->insert(['id' => $id, 'class_id' => $class, 'day' => $day, 'kind' => 'morning', 'submitted_by' => $this->owner->id, 'submitted_at' => $day.' 07:40:00']);
        foreach ($marks as $learner => $status) {
            DB::table('attendance_marks')->insert(['id' => Ids::next(), 'register_id' => $id, 'learner_id' => $learner, 'status' => $status, 'method' => 'tap',
                'captured_by' => $this->owner->id, 'captured_at' => $day.' 07:40:00', 'received_at' => $day.' 07:40:00']);
        }
    };
    foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-16', '2026-11-17', '2026-11-18'] as $i => $day) {
        $register($this->school->id('class_primary_a'), $day, [$this->primary[0] => 'present', $this->primary[1] => $i % 2 === 0 ? 'absent' : 'present']);
        $register($this->school->id('class_secondary_b'), $day, [$this->secondary[0] => 'present']);
    }
});

it('draws the whole-school overview in the school view only for those who may see it, every division apart', function (): void {
    $this->inPlace('institution', null, $this->owner);
    $data = $this->actingAs($this->owner)->getJson('/api/v1/dashboard')->assertOk()->json();

    expect($data['school'])->toBeTrue()
        ->and(array_column($data['views'], 'key'))->toContain('overview', 'enrolment', 'attendance')
        ->and($data['view'])->toBe('overview');
    $panels = collect($data['panels'])->keyBy('id');
    expect($panels['learners']['data']['value'])->toBe(4)
        ->and(collect($panels['learners_by_division']['data']['rows'])->pluck('value', 'name')->all())->toBe(['Primary' => 3, 'Secondary' => 1])
        ->and(collect($panels['attendance_by_division']['data']['series'])->pluck('name')->all())->toBe(['Primary', 'Secondary']);

    foreach (['enrolment', 'attendance'] as $view) {
        $this->actingAs($this->owner)->getJson('/api/v1/dashboard?view='.$view)->assertOk()->assertJsonPath('view', $view);
    }
    $attendance = collect($this->actingAs($this->owner)->getJson('/api/v1/dashboard?view=attendance')->json('panels'))->keyBy('id');
    expect($attendance['low']['data']['value'])->toBe(1)
        ->and(collect($attendance['attendance_heat']['data']['rows'])->pluck('name')->all())->toBe(['Primary · Area 47', 'Secondary · Blantyre']);

    // The school view's Today holds no one's lessons or classes: only what is school-wide.
    $today = $this->actingAs($this->owner)->getJson('/api/v1/today')->assertOk()->json();
    expect($today['now'])->toBe([])->and($today['chart'])->toBeNull();

    // A head of Primary alone may not open the whole-school overview.
    $head = User::factory()->create(['name' => 'Head of Primary']);
    $role = Role::query()->create(['key' => 'head_'.substr(Ids::next(), -8), 'name' => 'Head']);
    DB::table('access_role_permissions')->insert([['role_id' => $role->id, 'permission_key' => 'attendance.all_classes'], ['role_id' => $role->id, 'permission_key' => 'enrolment.learners.view']]);
    RoleAssignment::query()->create(['user_id' => $head->id, 'role_id' => $role->id, 'scope_type' => ScopeType::Division, 'scope_id' => $this->school->id('primary'), 'starts_at' => now()->subMinute()]);
    $this->inPlace('institution', null, $head);
    $this->actingAs($head)->getJson('/api/v1/dashboard')->assertForbidden();
    $this->actingAs($head)->getJson('/api/v1/school/learners')->assertForbidden();
    $this->actingAs($head)->getJson('/api/v1/fees/ledger')->assertForbidden();
    expect(InstitutionPermission::Overview->isSight())->toBeTrue();
});

it('keeps a division\'s dashboard and Today to the division', function (): void {
    $this->inPlace('division', $this->school->id('primary'), $this->owner);
    $data = $this->actingAs($this->owner)->getJson('/api/v1/dashboard')->assertOk()->json();
    expect($data['school'])->toBeFalse()
        ->and($data['views'][0])->toBe(['key' => 'overview', 'label' => 'Overview']);

    foreach (array_column($data['views'], 'key') as $view) {
        $body = (string) $this->actingAs($this->owner)->getJson('/api/v1/dashboard?view='.$view)->assertOk()->getContent();
        expect($body)->not->toContain('Secondary')->not->toContain('Quokkaline');
    }

    $today = $this->actingAs($this->owner)->getJson('/api/v1/today')->assertOk()->json();
    expect($today)->toHaveKeys(['now', 'chart', 'items'])
        ->and(json_encode($today))->not->toContain('Secondary');
    // Primary at Blantyre took no register today: the head is told.
    expect(collect($today['items'])->firstWhere('key', 'registers_late'))->not->toBeNull();
});

it('gives a class teacher their morning register and their week', function (): void {
    $teacher = User::factory()->create(['name' => 'Tadala Mvula']);
    DB::table('academic_teaching')->insert(['id' => Ids::next(), 'class_id' => $this->school->id('class_primary_a'), 'subject_id' => null, 'user_id' => $teacher->id,
        'given_by' => $this->owner->id, 'given_at' => now()->subMonth(), 'ended_at' => null]);
    $role = Role::query()->create(['key' => 'ct_'.substr(Ids::next(), -8), 'name' => 'Class teacher']);
    DB::table('access_role_permissions')->insert(['role_id' => $role->id, 'permission_key' => 'attendance.capture']);
    RoleAssignment::query()->create(['user_id' => $teacher->id, 'role_id' => $role->id, 'scope_type' => ScopeType::ClassGroup, 'scope_id' => $this->school->id('class_primary_a'), 'starts_at' => now()->subMinute()]);
    $this->noPlace($teacher);

    $today = $this->actingAs($teacher)->getJson('/api/v1/today')->assertOk()->json();
    expect($today['now'][0]['title'])->toBe('STD1')
        ->and($today['now'][0]['state'])->toBe(['tone' => 'attention', 'text' => 'Not taken'])
        ->and($today['chart']['kind'])->toBe('columns')
        ->and($today['chart']['values'])->toBe([50, 100, 50, null, null]);
});

it('draws every view of both dashboards, and keeps a division to itself in each', function (): void {
    $this->inPlace('institution', null, $this->owner);
    $views = array_column($this->actingAs($this->owner)->getJson('/api/v1/dashboard')->assertOk()->json('views'), 'key');
    expect($views)->toBe(['overview', 'enrolment', 'attendance', 'fees', 'results', 'conduct']);
    foreach ($views as $view) {
        $panels = $this->actingAs($this->owner)->getJson('/api/v1/dashboard?view='.$view)->assertOk()->json('panels');
        expect($panels)->not->toBeEmpty();
    }

    $this->inPlace('division', $this->school->id('primary'), $this->owner);
    foreach ($views as $view) {
        $body = (string) $this->actingAs($this->owner)->getJson('/api/v1/dashboard?view='.$view)->assertOk()->getContent();
        expect($body)->not->toContain('Secondary')->not->toContain('Quokkaline');
    }
});

it('lists every learner of the school, read-only, grouped by division, in the school view only', function (): void {
    $this->inPlace('institution', null, $this->owner);
    $data = $this->actingAs($this->owner)->getJson('/api/v1/school/learners')->assertOk()->json();
    expect($data['total'])->toBe(4)
        ->and(array_column($data['groups'], 'division'))->toBe(['Primary', 'Secondary'])
        ->and(array_column($data['groups'][0]['rows'], 'campus'))->toContain('Area 47', 'Blantyre');
    expect($this->actingAs($this->owner)->getJson('/api/v1/school/learners?q=Quokka')->json('total'))->toBe(1);
    $this->actingAs($this->owner)->getJson('/api/v1/fees/ledger')->assertOk()->assertJsonStructure(['from', 'to', 'total', 'offices']);

    // Inside a division there is no whole-school list.
    $this->inPlace('division', $this->school->id('primary'), $this->owner);
    $this->actingAs($this->owner)->getJson('/api/v1/school/learners')->assertNotFound();
    $this->actingAs($this->owner)->getJson('/api/v1/fees/ledger')->assertNotFound();
});

it('draws the dashboard of a division taught at one campus, and keeps it to that division', function (): void {
    // Secondary is taught at Blantyre only: its place lists no offering of its own.
    $this->inPlace('division', $this->school->id('secondary'), $this->owner);
    $data = $this->actingAs($this->owner)->getJson('/api/v1/dashboard')->assertOk()->json();
    expect(array_column($data['views'], 'key'))->toContain('overview', 'attendance')
        ->and(json_encode($data))->not->toContain('Primary')->not->toContain('Alinafe');
});

it('keeps a finance office\'s dashboard and Today to fees and finding a learner', function (): void {
    DB::table('fee_office_staff')->insert(['office_id' => 'school', 'user_id' => $this->owner->id, 'added_at' => now()]);
    $this->inPlace('office', 'school', $this->owner);

    $views = array_column($this->actingAs($this->owner)->getJson('/api/v1/dashboard')->assertOk()->json('views'), 'key');
    expect($views)->not->toContain('attendance', 'results', 'conduct');
    $today = $this->actingAs($this->owner)->getJson('/api/v1/today')->assertOk()->json();
    expect($today['now'])->toBe([])->and($today['chart'])->toBeNull()
        ->and(array_column($today['items'], 'key'))->not->toContain('registers_late');
});
