<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Module\ModuleState;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/*
 * Programmes (D-097): faculties and departments, the course catalogue,
 * programmes with curriculum versions that are checked and then released,
 * and each semester's offerings with their lecturers.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-19 10:00:00');
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'programmes', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    app()->forgetScopedInstances();

    $now = now();
    $this->college = Ids::next();
    DB::table('institution_divisions')->insert(['id' => $this->college, 'name' => 'College', 'profile_ref' => 'TERTIARY@2026.1', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $this->other = Ids::next();
    DB::table('institution_divisions')->insert(['id' => $this->other, 'name' => 'Secondary', 'profile_ref' => 'SECONDARY@2026.1', 'position' => 2, 'created_at' => $now, 'updated_at' => $now]);
    $year = Ids::next();
    DB::table('academic_years')->insert(['id' => $year, 'name' => '2026/27', 'starts_on' => '2026-09-07', 'ends_on' => '2027-07-23', 'created_at' => $now, 'updated_at' => $now]);
    $this->semesters = [];
    foreach ([1 => ['2026-09-07', '2026-12-18'], 2 => ['2027-01-18', '2027-05-07']] as $position => [$from, $to]) {
        $this->semesters[$position] = Ids::next();
        DB::table('academic_periods')->insert(['id' => $this->semesters[$position], 'year_id' => $year, 'division_id' => $this->college, 'name' => "Semester {$position}",
            'position' => $position, 'starts_on' => $from, 'ends_on' => $to, 'created_at' => $now, 'updated_at' => $now]);
    }

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->base = "/api/v1/programmes/{$this->college}";

    $this->makeStructure = function (): array {
        $this->actingAs($this->admin)->putJson("{$this->base}/faculties", ['faculties' => [['id' => null, 'name' => 'Faculty of Commerce', 'code' => 'com', 'retired' => false]]])->assertOk();
        $faculty = (string) DB::table('programme_faculties')->where('division_id', $this->college)->value('id');
        $department = $this->actingAs($this->admin)->postJson("{$this->base}/departments", ['id' => null, 'faculty_id' => $faculty, 'name' => 'Accounting and Finance',
            'code' => 'ACF', 'prefixes' => ['acc', 'LAW'], 'head_user_id' => null, 'retired' => false])->assertOk()->json('id');
        $course = function (string $code, array $needs = []) use ($department): string {
            return $this->actingAs($this->admin)->postJson("{$this->base}/courses", ['id' => null, 'department_id' => $department, 'code' => $code, 'title' => "Course {$code}",
                'credits' => 15, 'lecture_hours' => 3, 'tutorial_hours' => 1, 'practical_hours' => 0, 'needs' => $needs, 'retired' => false])->assertOk()->json('id');
        };
        $c = [];
        $c['ACC 101'] = $course('acc101');
        $c['ACC 102'] = $course('ACC 102', [$c['ACC 101']]);
        $c['LAW 101'] = $course('LAW 101');
        $c['MKT 101'] = $course('MKT 101');
        $c['ECO 101'] = $course('ECO 101');
        $programme = $this->actingAs($this->admin)->postJson("{$this->base}/programmes", ['id' => null, 'department_id' => $department, 'code' => 'CAC', 'name' => 'Certificate in Accounting',
            'award' => 'certificate', 'years' => 1, 'semesters' => 2, 'credits' => 60, 'min_load' => 15, 'max_load' => 45, 'open' => true, 'retired' => false])->assertOk()->json('id');

        return [$department, $c, $programme];
    };
});

it('keeps faculties, departments and a catalogue with codes written one way', function (): void {
    [$department, $c] = ($this->makeStructure)();
    $data = $this->actingAs($this->admin)->getJson("{$this->base}/structure")->assertOk()->json();

    expect($data['faculties'][0]['code'])->toBe('COM')
        ->and($data['faculties'][0]['departments'][0]['prefixes'])->toBe(['ACC', 'LAW'])
        ->and($data['faculties'][0]['departments'][0]['courses'])->toBe(5)
        ->and($data['programmes'][0]['code'])->toBe('CAC');

    $catalogue = $this->actingAs($this->admin)->getJson("{$this->base}/catalogue")->assertOk()->json('courses');
    expect(array_column($catalogue, 'code'))->toBe(['ACC 101', 'ACC 102', 'ECO 101', 'LAW 101', 'MKT 101'])
        ->and($catalogue[1]['needs'][0]['code'])->toBe('ACC 101');

    // A course may not need a course that needs it.
    $this->actingAs($this->admin)->postJson("{$this->base}/courses", ['id' => $c['ACC 101'], 'department_id' => $department, 'code' => 'ACC 101', 'title' => 'Course ACC 101',
        'credits' => 15, 'lecture_hours' => 3, 'tutorial_hours' => 1, 'practical_hours' => 0, 'needs' => [$c['ACC 102']], 'retired' => false])->assertUnprocessable();
    // Two courses may not share a code.
    $this->actingAs($this->admin)->postJson("{$this->base}/courses", ['id' => null, 'department_id' => $department, 'code' => 'acc 101', 'title' => 'Again',
        'credits' => 15, 'lecture_hours' => 3, 'tutorial_hours' => 0, 'practical_hours' => 0, 'needs' => [], 'retired' => false])->assertUnprocessable();
});

it('checks a draft curriculum, releases it once it passes, and never changes it after', function (): void {
    [$department, $c, $programme] = ($this->makeStructure)();
    $draft = $this->actingAs($this->admin)->getJson("{$this->base}/programmes/{$programme}")->assertOk()->json('version');
    expect($draft['label'])->toBe('2026')->and($draft['released'])->toBeFalse();

    // ACC 102 in the same semester as the course it needs.
    $wrong = ['groups' => [['key' => 'g1', 'year' => 1, 'semester' => 2, 'label' => 'Elective group A', 'choose' => 1]],
        'courses' => [['course_id' => $c['ACC 101'], 'year' => 1, 'semester' => 1, 'group' => null], ['course_id' => $c['ACC 102'], 'year' => 1, 'semester' => 1, 'group' => null],
            ['course_id' => $c['LAW 101'], 'year' => 1, 'semester' => 2, 'group' => null], ['course_id' => $c['MKT 101'], 'year' => 1, 'semester' => 2, 'group' => 'g1'],
            ['course_id' => $c['ECO 101'], 'year' => 1, 'semester' => 2, 'group' => 'g1']]];
    $this->actingAs($this->admin)->putJson("{$this->base}/versions/{$draft['id']}", $wrong)->assertOk();
    $checked = $this->actingAs($this->admin)->getJson("{$this->base}/programmes/{$programme}")->json('version');
    expect(array_column($checked['problems'], 'kind'))->toBe(['needs'])
        ->and($checked['slots'])->toBe(['1.1' => 30, '1.2' => 30])
        ->and($checked['total'])->toBe(60);
    $this->actingAs($this->admin)->postJson("{$this->base}/versions/{$draft['id']}/release")->assertUnprocessable();

    $right = $wrong;
    $right['courses'][1]['semester'] = 2;
    $right['courses'][2]['semester'] = 1;
    $this->actingAs($this->admin)->putJson("{$this->base}/versions/{$draft['id']}", $right)->assertOk();
    $this->actingAs($this->admin)->postJson("{$this->base}/versions/{$draft['id']}/release")->assertOk();

    // Released: the version and the shape of the programme stay as they are.
    $this->actingAs($this->admin)->putJson("{$this->base}/versions/{$draft['id']}", $wrong)->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("{$this->base}/programmes", ['id' => $programme, 'department_id' => $department, 'code' => 'CAC', 'name' => 'Certificate in Accounting',
        'award' => 'certificate', 'years' => 2, 'semesters' => 2, 'credits' => 60, 'min_load' => 15, 'max_load' => 45, 'open' => true, 'retired' => false])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("{$this->base}/courses", ['id' => $c['LAW 101'], 'department_id' => $department, 'code' => 'LAW 101', 'title' => 'Business Law',
        'credits' => 20, 'lecture_hours' => 3, 'tutorial_hours' => 0, 'practical_hours' => 0, 'needs' => [], 'retired' => false])->assertUnprocessable();

    // A new version starts as a copy of the released one.
    $next = $this->actingAs($this->admin)->postJson("{$this->base}/programmes/{$programme}/versions")->assertOk()->json('id');
    $shown = $this->actingAs($this->admin)->getJson("{$this->base}/programmes/{$programme}")->json();
    expect($shown['version']['id'])->toBe($next)
        ->and($shown['version']['label'])->toBe('2026.2')
        ->and(count($shown['version']['courses']))->toBe(5)
        ->and(count($shown['versions']))->toBe(2);
    $this->actingAs($this->admin)->postJson("{$this->base}/programmes/{$programme}/versions")->assertUnprocessable();
});

it('prepares a semester from released curricula, splits a course and names its lecturers', function (): void {
    [, $c, $programme] = ($this->makeStructure)();
    $draft = $this->actingAs($this->admin)->getJson("{$this->base}/programmes/{$programme}")->json('version.id');
    $this->actingAs($this->admin)->putJson("{$this->base}/versions/{$draft}", ['groups' => [['key' => 'g', 'year' => 1, 'semester' => 2, 'label' => 'Electives', 'choose' => 1]],
        'courses' => [['course_id' => $c['ACC 101'], 'year' => 1, 'semester' => 1, 'group' => null], ['course_id' => $c['LAW 101'], 'year' => 1, 'semester' => 1, 'group' => null],
            ['course_id' => $c['ACC 102'], 'year' => 1, 'semester' => 2, 'group' => null], ['course_id' => $c['MKT 101'], 'year' => 1, 'semester' => 2, 'group' => 'g']]])->assertOk();

    // Nothing is offered from a draft.
    $first = $this->actingAs($this->admin)->getJson("{$this->base}/offerings?period={$this->semesters[1]}")->assertOk()->json();
    expect($first['missing'])->toBe(0)->and($first['period']['position'])->toBe(1);

    $this->actingAs($this->admin)->postJson("{$this->base}/versions/{$draft}/release")->assertOk();
    $this->actingAs($this->admin)->postJson("{$this->base}/offerings/{$this->semesters[1]}/prepare")->assertOk()->assertJson(['added' => 2]);
    $this->actingAs($this->admin)->postJson("{$this->base}/offerings/{$this->semesters[1]}/prepare")->assertOk()->assertJson(['added' => 0]);
    $this->actingAs($this->admin)->postJson("{$this->base}/offerings/{$this->semesters[1]}/groups", ['course_id' => $c['LAW 101']])->assertOk();

    $list = $this->actingAs($this->admin)->getJson("{$this->base}/offerings?period={$this->semesters[1]}")->json('offerings');
    expect(array_map(fn ($o) => $o['course']['code'].' '.$o['group'], $list))->toBe(['ACC 101 A', 'LAW 101 A', 'LAW 101 B'])
        ->and($list[0]['ready'])->toBeFalse();

    $lecturer = User::factory()->create(['name' => 'Peter Banda']);
    $role = Role::query()->create(['key' => 'lec_'.substr(Ids::next(), -12), 'name' => 'Lecturer']);
    RoleAssignment::query()->create(['user_id' => $lecturer->id, 'role_id' => $role->id, 'scope_type' => ScopeType::Division, 'scope_id' => $this->college, 'starts_at' => now()->subMinute()]);
    $stranger = User::factory()->create(['name' => 'No Role']);

    $this->actingAs($this->admin)->putJson("{$this->base}/offering/{$list[0]['id']}", ['limit' => 80, 'lecturers' => [$stranger->id], 'assistants' => []])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson("{$this->base}/offering/{$list[0]['id']}", ['limit' => 80, 'lecturers' => [$lecturer->id], 'assistants' => []])->assertOk();
    $saved = $this->actingAs($this->admin)->getJson("{$this->base}/offerings?period={$this->semesters[1]}")->json('offerings.0');
    expect($saved['ready'])->toBeTrue()->and($saved['limit'])->toBe(80)->and($saved['lecturers'][0]['name'])->toBe('Peter Banda');
});

it('lets a viewer see but not change, and keeps each division apart', function (): void {
    ($this->makeStructure)();
    $viewer = User::factory()->create(['name' => 'Ruth Mwale']);
    $role = Role::query()->create(['key' => 'view_'.substr(Ids::next(), -12), 'name' => 'Registry clerk']);
    $role->grant([ProgrammesPermission::View]);
    RoleAssignment::query()->create(['user_id' => $viewer->id, 'role_id' => $role->id, 'scope_type' => ScopeType::Division, 'scope_id' => $this->college, 'starts_at' => now()->subMinute()]);

    $data = $this->actingAs($viewer)->getJson("{$this->base}/structure")->assertOk()->json();
    expect($data['can'])->toBe(['manage' => false, 'offer' => false])->and($data['staff'])->toBe([]);
    $this->actingAs($viewer)->putJson("{$this->base}/faculties", ['faculties' => []])->assertForbidden();
    // Programmes is not a part of the other division at all (D-099): not found.
    $this->actingAs($viewer)->getJson("/api/v1/programmes/{$this->other}/structure")->assertNotFound();

    // A division whose profile sets study by classes has no programmes at all.
    $this->actingAs($this->admin)->getJson("/api/v1/programmes/{$this->other}/catalogue")->assertNotFound();
    $this->actingAs($this->admin)->putJson("/api/v1/programmes/{$this->other}/faculties", ['faculties' => []])->assertNotFound();
});

it('offers each division only the parts its profile lists', function (): void {
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    app()->forgetScopedInstances();
    $modules = app(ModuleActivation::class);
    $college = ScopeRef::of(ScopeType::Division, $this->college);
    $secondary = ScopeRef::of(ScopeType::Division, $this->other);

    // D-099: registers belong to the school divisions, programmes to the college; fees are shared.
    expect($modules->isActive('programmes', $college))->toBeTrue()
        ->and($modules->isActive('attendance', $college))->toBeFalse()
        ->and($modules->isActive('attendance', $secondary))->toBeTrue()
        ->and($modules->isActive('programmes', $secondary))->toBeFalse()
        ->and($modules->state('attendance', $college))->toBe(ModuleState::NotOffered);

    $divisions = collect($this->actingAs($this->admin)->getJson('/api/v1/me/context')->assertOk()->json('divisions'))->keyBy('id');
    expect($divisions[$this->college]['modules'])->toBe(['fees', 'conduct', 'support', 'programmes', 'news', 'lectures'])
        ->and($divisions[$this->other]['modules'])->toContain('attendance', 'assessment', 'fees');
});
