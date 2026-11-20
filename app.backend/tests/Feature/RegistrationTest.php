<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Fees\Contracts\FeesStanding;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/*
 * Semester registration (D-098): students placed in programmes, a semester
 * started for every student at once, the department's approval, the fees
 * gate, the slip, and a change of courses in add and drop as a new version.
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
    $campus = Ids::next();
    DB::table('institution_campuses')->insert(['id' => $campus, 'name' => 'Main', 'place' => 'Lilongwe', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('institution_offerings')->insert(['id' => Ids::next(), 'division_id' => $this->college, 'campus_id' => $campus, 'started_at' => $now]);
    $year = Ids::next();
    DB::table('academic_years')->insert(['id' => $year, 'name' => '2026/27', 'starts_on' => '2026-09-07', 'ends_on' => '2027-07-23', 'created_at' => $now, 'updated_at' => $now]);
    $this->semesters = [];
    foreach ([1 => ['2026-09-07', '2026-12-18'], 2 => ['2027-01-18', '2027-05-07']] as $position => [$from, $to]) {
        $this->semesters[$position] = Ids::next();
        DB::table('academic_periods')->insert(['id' => $this->semesters[$position], 'year_id' => $year, 'division_id' => $this->college, 'name' => "Semester {$position}",
            'position' => $position, 'starts_on' => $from, 'ends_on' => $to, 'created_at' => $now, 'updated_at' => $now]);
    }
    $class = Ids::next();
    DB::table('academic_classes')->insert(['id' => $class, 'year_id' => $year, 'division_id' => $this->college, 'campus_id' => $campus, 'level_code' => 'Y1',
        'stream' => null, 'name' => 'Year 1', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);

    $this->learners = [];
    foreach (['Chikondi Banda', 'Thoko Mwale', 'Kondwani Phiri'] as $i => $name) {
        [$first, $surname] = explode(' ', $name);
        $learner = Ids::next();
        $enrolment = Ids::next();
        DB::table('learners')->insert(['id' => $learner, 'acadly_id' => 'AL-3000-000'.$i, 'first_name' => $first, 'surname' => $surname, 'sex' => 'female',
            'date_of_birth' => '2005-01-01', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('enrolments')->insert(['id' => $enrolment, 'learner_id' => $learner, 'status' => 'active', 'admitted_on' => '2026-09-07', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('placements')->insert(['id' => Ids::next(), 'enrolment_id' => $enrolment, 'learner_id' => $learner, 'year_id' => $year, 'division_id' => $this->college,
            'campus_id' => $campus, 'level_code' => 'Y1', 'class_id' => $class, 'starts_on' => '2026-09-07', 'entry_reason' => 'admission', 'created_at' => $now]);
        $this->learners[] = $learner;
    }

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->base = "/api/v1/programmes/{$this->college}";

    // A one-year certificate: two core courses in semester 1; ACC 102 (needs ACC 101) and one elective of two in semester 2.
    $this->actingAs($this->admin)->putJson("{$this->base}/faculties", ['faculties' => [['id' => null, 'name' => 'Faculty of Commerce', 'code' => 'COM', 'retired' => false]]])->assertOk();
    $faculty = (string) DB::table('programme_faculties')->where('division_id', $this->college)->value('id');
    $department = $this->actingAs($this->admin)->postJson("{$this->base}/departments", ['id' => null, 'faculty_id' => $faculty, 'name' => 'Accounting and Finance',
        'code' => 'ACF', 'prefixes' => ['ACC', 'LAW', 'MKT', 'ECO'], 'head_user_id' => null, 'retired' => false])->assertOk()->json('id');
    $this->c = [];
    foreach (['ACC 101' => [], 'ACC 102' => ['ACC 101'], 'LAW 101' => [], 'MKT 101' => [], 'ECO 101' => []] as $code => $needs) {
        $this->c[$code] = $this->actingAs($this->admin)->postJson("{$this->base}/courses", ['id' => null, 'department_id' => $department, 'code' => $code, 'title' => "Course {$code}",
            'credits' => 15, 'lecture_hours' => 3, 'tutorial_hours' => 1, 'practical_hours' => 0, 'needs' => array_map(fn (string $n): string => $this->c[$n], $needs),
            'retired' => false])->assertOk()->json('id');
    }
    $this->programme = $this->actingAs($this->admin)->postJson("{$this->base}/programmes", ['id' => null, 'department_id' => $department, 'code' => 'CAC',
        'name' => 'Certificate in Accounting', 'award' => 'certificate', 'years' => 1, 'semesters' => 2, 'credits' => 60, 'min_load' => 15, 'max_load' => 45,
        'open' => true, 'retired' => false])->assertOk()->json('id');
    $draft = $this->actingAs($this->admin)->getJson("{$this->base}/programmes/{$this->programme}")->json('version.id');
    $this->actingAs($this->admin)->putJson("{$this->base}/versions/{$draft}", ['groups' => [['key' => 'g', 'year' => 1, 'semester' => 2, 'label' => 'Elective group A', 'choose' => 1]],
        'courses' => [['course_id' => $this->c['ACC 101'], 'year' => 1, 'semester' => 1, 'group' => null], ['course_id' => $this->c['LAW 101'], 'year' => 1, 'semester' => 1, 'group' => null],
            ['course_id' => $this->c['ACC 102'], 'year' => 1, 'semester' => 2, 'group' => null], ['course_id' => $this->c['MKT 101'], 'year' => 1, 'semester' => 2, 'group' => 'g'],
            ['course_id' => $this->c['ECO 101'], 'year' => 1, 'semester' => 2, 'group' => 'g']]])->assertOk();
    $this->actingAs($this->admin)->postJson("{$this->base}/versions/{$draft}/release")->assertOk();
    foreach ($this->semesters as $period) {
        $this->actingAs($this->admin)->postJson("{$this->base}/offerings/{$period}/prepare")->assertOk();
        $this->actingAs($this->admin)->putJson("{$this->base}/registration/{$period}/window", ['opens_on' => '2026-11-01', 'closes_on' => '2026-11-30',
            'late_until' => '2026-12-05', 'add_drop_until' => '2026-12-10', 'late_fee' => 2500000, 'fees_share' => 50])->assertOk();
    }

    $this->placeAll = function (array $learners): void {
        foreach ($learners as $learner) {
            $this->actingAs($this->admin)->postJson("{$this->base}/students", ['learner_id' => $learner, 'programme_id' => $this->programme, 'entry_year' => 1, 'mode' => 'full_time'])->assertOk();
        }
    };
    $this->offering = fn (string $period, string $code, string $group = 'A'): string => (string) DB::table('programme_offerings')
        ->where('period_id', $period)->where('course_id', $this->c[$code])->where('group_label', $group)->value('id');
});

it('places students and starts a semester for all of them at once', function (): void {
    ($this->placeAll)([$this->learners[0], $this->learners[1]]);
    $this->actingAs($this->admin)->postJson("{$this->base}/students", ['learner_id' => $this->learners[0], 'programme_id' => $this->programme, 'entry_year' => 1, 'mode' => 'full_time'])
        ->assertUnprocessable();

    // LAW 101 in two groups of one seat each: the students spread over them.
    $groupB = $this->actingAs($this->admin)->postJson("{$this->base}/offerings/{$this->semesters[1]}/groups", ['course_id' => $this->c['LAW 101']])->assertOk()->json('id');
    $this->actingAs($this->admin)->putJson("{$this->base}/offering/".($this->offering)($this->semesters[1], 'LAW 101'), ['limit' => 1, 'lecturers' => [], 'assistants' => []])->assertOk();
    $this->actingAs($this->admin)->putJson("{$this->base}/offering/{$groupB}", ['limit' => 1, 'lecturers' => [], 'assistants' => []])->assertOk();

    $started = $this->actingAs($this->admin)->postJson("{$this->base}/registration/{$this->semesters[1]}/start")->assertOk()->json();
    expect($started)->toBe(['opened' => 2, 'sent' => 2, 'completed' => 0]);

    $desk = $this->actingAs($this->admin)->getJson("{$this->base}/registration?period={$this->semesters[1]}")->assertOk()->json();
    expect(array_column($desk['rows'], 'state'))->toBe(['submitted', 'submitted'])
        ->and(array_column($desk['rows'], 'credits'))->toBe([30, 30])
        ->and($desk['not_started'])->toBe([])
        ->and($desk['window']['started_at'])->not->toBeNull();
    $groups = DB::table('programme_registration_courses as rc')->join('programme_offerings as o', 'o.id', '=', 'rc.offering_id')
        ->where('rc.course_id', $this->c['LAW 101'])->orderBy('o.group_label')->pluck('o.group_label')->all();
    expect($groups)->toBe(['A', 'B']);

    // The department approves; with Fees off, nothing waits for fees.
    $approved = $this->actingAs($this->admin)->postJson("{$this->base}/registration/{$this->semesters[1]}/approve", ['ids' => array_column($desk['rows'], 'id')])->assertOk()->json();
    expect($approved)->toBe(['approved' => 2, 'registered' => 2]);

    $students = $this->actingAs($this->admin)->getJson("{$this->base}/students?period={$this->semesters[1]}")->assertOk()->json();
    expect(array_column(array_column($students['students'], 'registration'), 'state'))->toBe(['registered', 'registered'])
        ->and(array_column($students['candidates'], 'id'))->toBe([$this->learners[2]])
        ->and($students['programmes'][0]['curriculum'])->toBe('2026');

    $slip = $this->actingAs($this->admin)->get("{$this->base}/registrations/{$desk['rows'][0]['id']}/slip")->assertOk();
    expect($slip->headers->get('Content-Type'))->toBe('application/pdf');
    expect(DB::table('document_checks')->where('kind', 'programmes.registration_slip')->count())->toBe(1);

    // The learner record reads the programme and the registration.
    $record = $this->actingAs($this->admin)->getJson("/api/v1/programmes/learners/{$this->learners[0]}")->assertOk()->json();
    expect($record['programme']['code'])->toBe('CAC')
        ->and($record['registrations'][0]['state'])->toBe('registered')
        ->and(count($record['registrations'][0]['courses']))->toBe(2);
    $this->actingAs($this->admin)->getJson("/api/v1/programmes/learners/{$this->learners[2]}")->assertNotFound();

    // Starting again opens nothing new.
    expect($this->actingAs($this->admin)->postJson("{$this->base}/registration/{$this->semesters[1]}/start")->json('opened'))->toBe(0);
});

it('waits for an elective choice, checks courses needed first, and keeps each version in add and drop', function (): void {
    ($this->placeAll)([$this->learners[0]]);
    $this->actingAs($this->admin)->postJson("{$this->base}/registration/{$this->semesters[1]}/start")->assertOk();
    $first = (string) DB::table('programme_registrations')->where('period_id', $this->semesters[1])->value('id');
    $this->actingAs($this->admin)->postJson("{$this->base}/registration/{$this->semesters[1]}/approve", ['ids' => [$first]])->assertOk();

    // A second student joins after semester 1: in semester 2, ACC 102 needs ACC 101, which they have not taken.
    ($this->placeAll)([$this->learners[1]]);
    $started = $this->actingAs($this->admin)->postJson("{$this->base}/registration/{$this->semesters[2]}/start")->assertOk()->json();
    expect($started)->toBe(['opened' => 2, 'sent' => 0, 'completed' => 0]);

    $mine = (string) DB::table('programme_registrations')->where('period_id', $this->semesters[2])->where('learner_id', $this->learners[0])->value('id');
    $theirs = (string) DB::table('programme_registrations')->where('period_id', $this->semesters[2])->where('learner_id', $this->learners[1])->value('id');
    $shown = $this->actingAs($this->admin)->getJson("{$this->base}/registrations/{$mine}")->assertOk()->json();
    expect(array_column($shown['checks']['problems'], 'kind'))->toBe(['group'])
        ->and(array_column(array_column($shown['courses'], 'course'), 'code'))->toBe(['ACC 102'])
        ->and(collect($shown['steps'])->firstWhere('step', 'submit')['blocked_reason'])->toBe('Elective group A: choose 1; 0 chosen.');
    $this->actingAs($this->admin)->postJson("{$this->base}/registrations/{$mine}/submit")->assertUnprocessable();

    $courses = [($this->offering)($this->semesters[2], 'ACC 102'), ($this->offering)($this->semesters[2], 'MKT 101')];
    $this->actingAs($this->admin)->putJson("{$this->base}/registrations/{$mine}/courses", ['offerings' => $courses])->assertOk();
    $this->actingAs($this->admin)->putJson("{$this->base}/registrations/{$theirs}/courses", ['offerings' => $courses])->assertOk();
    $theirChecks = $this->actingAs($this->admin)->getJson("{$this->base}/registrations/{$theirs}")->json('checks.problems');
    expect(array_column($theirChecks, 'text'))->toBe(['ACC 102 needs ACC 101 first.']);

    $this->actingAs($this->admin)->postJson("{$this->base}/registrations/{$mine}/submit")->assertOk();
    $this->actingAs($this->admin)->postJson("{$this->base}/registration/{$this->semesters[2]}/approve", ['ids' => [$mine]])->assertOk();
    $registered = $this->actingAs($this->admin)->getJson("{$this->base}/registrations/{$mine}")->json();
    expect($registered['state'])->toBe('registered')
        ->and(array_column($registered['courses'], 'kind'))->toBe(['core', 'elective']);

    // Courses already registered change only as a new version, with a reason; version 1 stays.
    $this->actingAs($this->admin)->putJson("{$this->base}/registrations/{$mine}/courses", ['offerings' => [$courses[0]]])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("{$this->base}/registrations/{$mine}/change", ['reason' => ''])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("{$this->base}/registrations/{$mine}/change", ['reason' => 'Changes elective to economics.'])->assertOk();
    $this->actingAs($this->admin)->putJson("{$this->base}/registrations/{$mine}/courses", ['offerings' => [$courses[0], ($this->offering)($this->semesters[2], 'ECO 101')]])->assertOk();
    $changed = $this->actingAs($this->admin)->getJson("{$this->base}/registrations/{$mine}")->json();
    expect($changed['version'])->toBe(2)
        ->and($changed['state'])->toBe('draft')
        ->and(array_column(array_column($changed['courses'], 'course'), 'code'))->toBe(['ACC 102', 'ECO 101'])
        ->and($changed['history'][0]['reason'])->toBe('Changes elective to economics.')
        ->and(DB::table('programme_registration_courses')->where('registration_id', $mine)->where('version', 1)->count())->toBe(2);

    // After add and drop, no more changes.
    $this->actingAs($this->admin)->postJson("{$this->base}/registrations/{$mine}/submit")->assertOk();
    $this->actingAs($this->admin)->postJson("{$this->base}/registration/{$this->semesters[2]}/approve", ['ids' => [$mine]])->assertOk();
    $this->travelTo('2026-12-11 10:00:00');
    $this->actingAs($this->admin)->postJson("{$this->base}/registrations/{$mine}/change", ['reason' => 'Late change.'])->assertUnprocessable();
});

it('holds registration until the fees needed are paid, or allowed with a reason', function (): void {
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'fees', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    app()->forgetScopedInstances();
    app()->instance(FeesStanding::class, new class implements FeesStanding
    {
        public function owedAndPaid(string $learnerId): array
        {
            return ['owed' => 10000000, 'paid' => 2000000];
        }

        public function ownFees(string $learnerId): array
        {
            return ['billed' => 10000000, 'paid' => 2000000, 'left' => 8000000, 'due_now' => 0, 'instalments' => [], 'payments' => []];
        }

        public function ownStatement(User $viewer, string $learnerId): array
        {
            return ['name' => 'Statement.pdf', 'pdf' => ''];
        }
    });

    ($this->placeAll)([$this->learners[0]]);
    $this->actingAs($this->admin)->postJson("{$this->base}/registration/{$this->semesters[1]}/start")->assertOk();
    $id = (string) DB::table('programme_registrations')->where('period_id', $this->semesters[1])->value('id');
    expect($this->actingAs($this->admin)->postJson("{$this->base}/registration/{$this->semesters[1]}/approve", ['ids' => [$id]])->json())->toBe(['approved' => 1, 'registered' => 0]);

    $shown = $this->actingAs($this->admin)->getJson("{$this->base}/registrations/{$id}")->json();
    expect($shown['state'])->toBe('approved')
        ->and($shown['fees'])->toMatchArray(['known' => true, 'share' => 50, 'needed' => 5000000, 'more' => 3000000, 'cleared' => false]);
    expect($this->actingAs($this->admin)->postJson("{$this->base}/registration/{$this->semesters[1]}/register")->json('registered'))->toBe(0);

    $this->actingAs($this->admin)->postJson("{$this->base}/registrations/{$id}/allow-fees", ['reason' => ''])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("{$this->base}/registrations/{$id}/allow-fees", ['reason' => 'Sponsor letter received.'])->assertOk();
    $done = $this->actingAs($this->admin)->getJson("{$this->base}/registrations/{$id}")->json();
    expect($done['state'])->toBe('registered')
        ->and($done['fees']['allowed']['reason'])->toBe('Sponsor letter received.');
});

it('lets a viewer see registration but not change it', function (): void {
    ($this->placeAll)([$this->learners[0]]);
    $this->actingAs($this->admin)->postJson("{$this->base}/registration/{$this->semesters[1]}/start")->assertOk();
    $id = (string) DB::table('programme_registrations')->value('id');

    $role = Role::query()->create(['key' => 'view_'.substr(Ids::next(), -12), 'name' => 'Registry clerk']);
    $role->grant([ProgrammesPermission::View]);
    $viewer = User::factory()->create(['name' => 'Peter Banda']);
    RoleAssignment::query()->create(['user_id' => $viewer->id, 'role_id' => $role->id, 'scope_type' => ScopeType::Division, 'scope_id' => $this->college, 'starts_at' => now()->subMinute()]);

    $desk = $this->actingAs($viewer)->getJson("{$this->base}/registration?period={$this->semesters[1]}")->assertOk()->json();
    expect($desk['can'])->toBe(['register' => false, 'approve' => false, 'allow_fees' => false]);
    expect($this->actingAs($viewer)->getJson("{$this->base}/students")->assertOk()->json('candidates'))->toBe([]);
    $this->actingAs($viewer)->postJson("{$this->base}/registration/{$this->semesters[1]}/approve", ['ids' => [$id]])->assertForbidden();
    $this->actingAs($viewer)->postJson("{$this->base}/registrations/{$id}/withdraw", ['reason' => 'No.'])->assertForbidden();
    $this->actingAs($viewer)->postJson("{$this->base}/students", ['learner_id' => $this->learners[1], 'programme_id' => $this->programme, 'entry_year' => 1, 'mode' => 'full_time'])->assertForbidden();
});
