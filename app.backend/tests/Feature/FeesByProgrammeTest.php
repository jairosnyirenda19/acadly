<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/*
 * A college's term fees by programme and mode (D-116): an amount per year of
 * study replaces the term's default for that item, and a part-time student is
 * billed per registered credit, only once a registration is approved.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-19 10:00:00');
    $this->artisan('acadly:sync')->assertSuccessful();
    foreach (['programmes', 'fees'] as $module) {
        DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => $module, 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    }
    app()->forgetScopedInstances();
    $now = now();
    $this->college = Ids::next();
    DB::table('institution_divisions')->insert(['id' => $this->college, 'name' => 'College', 'profile_ref' => 'TERTIARY@2026.1', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $campus = Ids::next();
    DB::table('institution_campuses')->insert(['id' => $campus, 'name' => 'Main', 'place' => 'Lilongwe', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('institution_offerings')->insert(['id' => Ids::next(), 'division_id' => $this->college, 'campus_id' => $campus, 'started_at' => $now]);
    $year = Ids::next();
    DB::table('academic_years')->insert(['id' => $year, 'name' => '2026/27', 'starts_on' => '2026-09-07', 'ends_on' => '2027-07-23', 'created_at' => $now, 'updated_at' => $now]);
    $this->semester = Ids::next();
    DB::table('academic_periods')->insert(['id' => $this->semester, 'year_id' => $year, 'division_id' => $this->college, 'name' => 'Semester 1', 'position' => 1,
        'starts_on' => '2026-09-07', 'ends_on' => '2026-12-18', 'created_at' => $now, 'updated_at' => $now]);
    $class = Ids::next();
    DB::table('academic_classes')->insert(['id' => $class, 'year_id' => $year, 'division_id' => $this->college, 'campus_id' => $campus, 'level_code' => 'Y1',
        'stream' => null, 'name' => 'Year 1', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $this->learners = [];
    foreach (['Chikondi Banda', 'Tiwonge Chirwa', 'Mphatso Nkhoma'] as $i => $name) {
        [$first, $surname] = explode(' ', $name);
        $learner = Ids::next();
        $enrolment = Ids::next();
        DB::table('learners')->insert(['id' => $learner, 'acadly_id' => 'AL-7100-000'.$i, 'first_name' => $first, 'surname' => $surname, 'sex' => 'female',
            'date_of_birth' => '2005-01-01', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('enrolments')->insert(['id' => $enrolment, 'learner_id' => $learner, 'status' => 'active', 'admitted_on' => '2026-09-07', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('placements')->insert(['id' => Ids::next(), 'enrolment_id' => $enrolment, 'learner_id' => $learner, 'year_id' => $year, 'division_id' => $this->college,
            'campus_id' => $campus, 'level_code' => 'Y1', 'class_id' => $class, 'starts_on' => '2026-09-07', 'entry_reason' => 'admission', 'created_at' => $now]);
        $this->learners[] = $learner;
    }
    $this->admin = User::factory()->create(['name' => 'Grace Kamanga']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->staffWorkIn('division', $this->college);
    DB::table('fee_office_staff')->insert(['office_id' => 'school', 'user_id' => $this->admin->id, 'added_at' => now()]);

    $base = "/api/v1/programmes/{$this->college}";
    $this->actingAs($this->admin)->putJson("{$base}/faculties", ['faculties' => [['id' => null, 'name' => 'Faculty of Commerce', 'code' => 'COM', 'retired' => false]]])->assertOk();
    $faculty = (string) DB::table('programme_faculties')->where('division_id', $this->college)->value('id');
    $department = $this->actingAs($this->admin)->postJson("{$base}/departments", ['id' => null, 'faculty_id' => $faculty, 'name' => 'Accounting and Finance',
        'code' => 'ACF', 'prefixes' => ['ACC'], 'head_user_id' => null, 'retired' => false])->assertOk()->json('id');
    $course = $this->actingAs($this->admin)->postJson("{$base}/courses", ['id' => null, 'department_id' => $department, 'code' => 'ACC 101', 'title' => 'Financial Accounting I',
        'credits' => 15, 'lecture_hours' => 3, 'tutorial_hours' => 1, 'practical_hours' => 0, 'needs' => [], 'retired' => false])->assertOk()->json('id');
    $this->programme = $this->actingAs($this->admin)->postJson("{$base}/programmes", ['id' => null, 'department_id' => $department, 'code' => 'DAC',
        'name' => 'Diploma in Accounting', 'award' => 'diploma', 'years' => 1, 'semesters' => 1, 'credits' => 15, 'min_load' => 15, 'max_load' => 45,
        'open' => true, 'retired' => false])->assertOk()->json('id');
    $draft = $this->actingAs($this->admin)->getJson("{$base}/programmes/{$this->programme}")->json('version.id');
    $this->actingAs($this->admin)->putJson("{$base}/versions/{$draft}", ['groups' => [], 'courses' => [['course_id' => $course, 'year' => 1, 'semester' => 1, 'group' => null]]])->assertOk();
    $this->actingAs($this->admin)->postJson("{$base}/versions/{$draft}/release")->assertOk();
    $this->actingAs($this->admin)->postJson("{$base}/offerings/{$this->semester}/prepare")->assertOk();
    foreach (['full_time', 'part_time', 'part_time'] as $i => $mode) {
        $this->actingAs($this->admin)->postJson("{$base}/students", ['learner_id' => $this->learners[$i], 'programme_id' => $this->programme, 'entry_year' => 1, 'mode' => $mode])->assertOk();
    }

    // Tiwonge's registration of 15 credits is approved; Mphatso has none yet.
    $student = (string) DB::table('programme_students')->where('learner_id', $this->learners[1])->value('id');
    $registration = Ids::next();
    DB::table('programme_registrations')->insert(['id' => $registration, 'division_id' => $this->college, 'period_id' => $this->semester, 'learner_id' => $this->learners[1],
        'student_id' => $student, 'year_of_study' => 1, 'semester' => 1, 'state' => 'approved', 'journey_ref' => 'test', 'late' => false, 'version' => 2,
        'opened_by' => $this->admin->id, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('programme_registration_courses')->insert(['id' => Ids::next(), 'registration_id' => $registration, 'version' => 2,
        'offering_id' => DB::table('programme_offerings')->where('period_id', $this->semester)->value('id'), 'course_id' => $course, 'kind' => 'core', 'credits' => 15]);

    $this->item = fn (string $name): string => (string) DB::table('fee_items')->where('name', $name)->value('id');
    $this->path = "/api/v1/fees/terms/{$this->semester}/{$this->college}";
});

it('bills each programme and mode its own amount, and a part-time student per approved credit', function (): void {
    $term = $this->actingAs($this->admin)->getJson($this->path)->assertOk();
    expect(array_column($term->json('programmes'), 'code'))->toBe(['DAC'])->and($term->json('programme_amounts'))->toBe([]);

    $tuition = ($this->item)('Tuition');
    $this->actingAs($this->admin)->putJson($this->path, [
        'amounts' => [
            ['item_id' => $tuition, 'level_code' => 'Y1', 'amount' => 50_000_00],
            ['item_id' => ($this->item)('Development fund'), 'level_code' => 'Y1', 'amount' => 10_000_00],
        ],
        'plan' => [['label' => 'At registration', 'due_on' => '2026-09-07', 'share_bp' => 10000]],
        'programme_amounts' => [
            ['item_id' => $tuition, 'programme_id' => $this->programme, 'mode' => 'full_time', 'level_code' => 'Y1', 'amount' => 420_000_00],
            ['item_id' => $tuition, 'programme_id' => $this->programme, 'mode' => 'part_time', 'level_code' => 'credit', 'amount' => 9_000_00],
        ],
    ])->assertOk();

    // Full time: 420,000 + 10,000. Part time with 15 credits: 135,000 + 10,000. Part time without an approved registration waits.
    expect($this->actingAs($this->admin)->getJson($this->path)->json('to_bill'))->toBe(['learners' => 2, 'total' => 430_000_00 + 145_000_00]);
    expect($this->actingAs($this->admin)->getJson($this->path)->json('programme_amounts'))->toHaveCount(2);
    $this->actingAs($this->admin)->postJson($this->path.'/bill')->assertOk()->assertJson(['learners' => 2]);

    // Once Mphatso's 30 credits are approved, billing again bills only her.
    $student = (string) DB::table('programme_students')->where('learner_id', $this->learners[2])->value('id');
    $registration = Ids::next();
    DB::table('programme_registrations')->insert(['id' => $registration, 'division_id' => $this->college, 'period_id' => $this->semester, 'learner_id' => $this->learners[2],
        'student_id' => $student, 'year_of_study' => 1, 'semester' => 1, 'state' => 'registered', 'journey_ref' => 'test', 'late' => false, 'version' => 1,
        'opened_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('programme_registration_courses')->insert(['id' => Ids::next(), 'registration_id' => $registration, 'version' => 1,
        'offering_id' => DB::table('programme_offerings')->where('period_id', $this->semester)->value('id'),
        'course_id' => DB::table('programme_courses')->value('id'), 'kind' => 'core', 'credits' => 30]);
    $this->actingAs($this->admin)->postJson($this->path.'/bill')->assertOk()->assertJson(['learners' => 1, 'total' => 280_000_00]);
});

it('refuses an amount for a programme of another division or an unknown mode', function (): void {
    $plan = [['label' => 'At registration', 'due_on' => '2026-09-07', 'share_bp' => 10000]];
    $tuition = ($this->item)('Tuition');
    foreach ([[Ids::next(), 'full_time', 'Y1'], [$this->programme, 'evening', 'Y1'], [$this->programme, 'full_time', 'Y9']] as [$programme, $mode, $level]) {
        $this->actingAs($this->admin)->putJson($this->path, ['amounts' => [], 'plan' => $plan,
            'programme_amounts' => [['item_id' => $tuition, 'programme_id' => $programme, 'mode' => $mode, 'level_code' => $level, 'amount' => 100]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['programme_amounts']);
    }
});
