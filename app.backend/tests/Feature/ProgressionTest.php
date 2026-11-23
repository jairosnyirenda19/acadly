<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Classroom\Contracts\ClassroomPermission;
use Acadly\Modules\Results\Application\Rules;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Standing, progression, awards and transcripts (D-106): the end-of-year
 * decisions worked out from released results and the rules, the board's
 * choice with a reason, applying them through Programmes (year of study and
 * carried courses), the graduation list from approval to conferral, and the
 * transcript: the student's copy, a request and an official one issued.
 */

uses(DatabaseTransactions::class);

/** A college with one course, three students, and the course's results released: 75, 40, and an absence the board deferred. */
function progressionCollege(object $test, int $years, int $credits): void
{
    $test->travelTo('2026-11-19 10:00:00');
    $test->artisan('acadly:sync')->assertSuccessful();
    foreach (['programmes', 'results'] as $module) {
        DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => $module, 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    }
    app()->forgetScopedInstances();
    $now = now();
    $test->college = Ids::next();
    DB::table('institution_divisions')->insert(['id' => $test->college, 'name' => 'College', 'profile_ref' => 'TERTIARY@2026.1', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $campus = Ids::next();
    DB::table('institution_campuses')->insert(['id' => $campus, 'name' => 'Main', 'place' => 'Lilongwe', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('institution_offerings')->insert(['id' => Ids::next(), 'division_id' => $test->college, 'campus_id' => $campus, 'started_at' => $now]);
    $year = Ids::next();
    DB::table('academic_years')->insert(['id' => $year, 'name' => '2026/27', 'starts_on' => '2026-09-07', 'ends_on' => '2027-07-23', 'created_at' => $now, 'updated_at' => $now]);
    $test->semester = Ids::next();
    DB::table('academic_periods')->insert(['id' => $test->semester, 'year_id' => $year, 'division_id' => $test->college, 'name' => 'Semester 1', 'position' => 1,
        'starts_on' => '2026-09-07', 'ends_on' => '2026-12-18', 'created_at' => $now, 'updated_at' => $now]);
    $class = Ids::next();
    DB::table('academic_classes')->insert(['id' => $class, 'year_id' => $year, 'division_id' => $test->college, 'campus_id' => $campus, 'level_code' => 'Y1',
        'stream' => null, 'name' => 'Year 1', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $test->learners = [];
    foreach (['Chikondi Banda', 'Tiwonge Chirwa', 'Mphatso Nkhoma'] as $i => $name) {
        [$first, $surname] = explode(' ', $name);
        $learner = Ids::next();
        $enrolment = Ids::next();
        DB::table('learners')->insert(['id' => $learner, 'acadly_id' => 'AL-7000-000'.$i, 'first_name' => $first, 'surname' => $surname, 'sex' => 'female',
            'date_of_birth' => '2005-01-01', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('enrolments')->insert(['id' => $enrolment, 'learner_id' => $learner, 'status' => 'active', 'admitted_on' => '2026-09-07', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('placements')->insert(['id' => Ids::next(), 'enrolment_id' => $enrolment, 'learner_id' => $learner, 'year_id' => $year, 'division_id' => $test->college,
            'campus_id' => $campus, 'level_code' => 'Y1', 'class_id' => $class, 'starts_on' => '2026-09-07', 'entry_reason' => 'admission', 'created_at' => $now]);
        $test->learners[] = $learner;
    }
    $test->admin = User::factory()->create(['name' => 'Grace Kamanga']);
    RoleAssignment::query()->create(['user_id' => $test->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $lecturers = Role::query()->create(['key' => 'progression_lecturer_test', 'name' => 'Lecturer']);
    $lecturers->grant([ClassroomPermission::Teach, ResultsPermission::Enter]);
    $test->lecturer = User::factory()->create(['name' => 'Agnes Chilembwe']);
    RoleAssignment::query()->create(['user_id' => $test->lecturer->id, 'role_id' => $lecturers->id, 'scope_type' => ScopeType::Division, 'scope_id' => $test->college, 'starts_at' => now()->subMinute()]);

    $base = "/api/v1/programmes/{$test->college}";
    $test->actingAs($test->admin)->putJson("{$base}/faculties", ['faculties' => [['id' => null, 'name' => 'Faculty of Commerce', 'code' => 'COM', 'retired' => false]]])->assertOk();
    $faculty = (string) DB::table('programme_faculties')->where('division_id', $test->college)->value('id');
    $department = $test->actingAs($test->admin)->postJson("{$base}/departments", ['id' => null, 'faculty_id' => $faculty, 'name' => 'Accounting and Finance',
        'code' => 'ACF', 'prefixes' => ['ACC'], 'head_user_id' => null, 'retired' => false])->assertOk()->json('id');
    $test->course = $test->actingAs($test->admin)->postJson("{$base}/courses", ['id' => null, 'department_id' => $department, 'code' => 'ACC 101', 'title' => 'Financial Accounting I',
        'credits' => 15, 'lecture_hours' => 3, 'tutorial_hours' => 1, 'practical_hours' => 0, 'needs' => [], 'retired' => false])->assertOk()->json('id');
    $test->programme = $test->actingAs($test->admin)->postJson("{$base}/programmes", ['id' => null, 'department_id' => $department, 'code' => 'DAC',
        'name' => 'Diploma in Accounting', 'award' => 'diploma', 'years' => $years, 'semesters' => 1, 'credits' => $credits, 'min_load' => 15, 'max_load' => 45,
        'open' => true, 'retired' => false])->assertOk()->json('id');
    $draft = $test->actingAs($test->admin)->getJson("{$base}/programmes/{$test->programme}")->json('version.id');
    $plan = [['course_id' => $test->course, 'year' => 1, 'semester' => 1, 'group' => null]];
    if ($years === 2) {
        $plan[] = ['course_id' => $test->actingAs($test->admin)->postJson("{$base}/courses", ['id' => null, 'department_id' => $department, 'code' => 'ACC 201',
            'title' => 'Intermediate Accounting', 'credits' => 15, 'lecture_hours' => 3, 'tutorial_hours' => 1, 'practical_hours' => 0, 'needs' => [], 'retired' => false])->assertOk()->json('id'),
            'year' => 2, 'semester' => 1, 'group' => null];
    }
    $test->actingAs($test->admin)->putJson("{$base}/versions/{$draft}", ['groups' => [], 'courses' => $plan])->assertOk();
    $test->actingAs($test->admin)->postJson("{$base}/versions/{$draft}/release")->assertOk();
    $test->actingAs($test->admin)->postJson("{$base}/offerings/{$test->semester}/prepare")->assertOk();
    $test->actingAs($test->admin)->putJson("{$base}/registration/{$test->semester}/window", ['opens_on' => '2026-11-01', 'closes_on' => '2026-11-30',
        'late_until' => '2026-12-05', 'add_drop_until' => '2026-12-10', 'late_fee' => 0, 'fees_share' => 0])->assertOk();
    foreach ($test->learners as $learner) {
        $test->actingAs($test->admin)->postJson("{$base}/students", ['learner_id' => $learner, 'programme_id' => $test->programme, 'entry_year' => 1, 'mode' => 'full_time'])->assertOk();
    }
    $test->actingAs($test->admin)->postJson("{$base}/registration/{$test->semester}/start")->assertOk();
    $test->offering = (string) DB::table('programme_offerings')->where('period_id', $test->semester)->where('course_id', $test->course)->value('id');
    $test->actingAs($test->admin)->putJson("{$base}/offering/{$test->offering}", ['limit' => null, 'lecturers' => [$test->lecturer->id], 'assistants' => []])->assertOk();

    $test->base = "/api/v1/results/{$test->college}";
    $test->actingAs($test->lecturer)->putJson("{$test->base}/courses/{$test->offering}/marks", ['marks' => [
        ['learner_id' => $test->learners[0], 'exam' => 75], ['learner_id' => $test->learners[1], 'exam' => 40], ['learner_id' => $test->learners[2], 'absent' => true],
    ]])->assertOk();
    $test->actingAs($test->lecturer)->postJson("{$test->base}/courses/{$test->offering}/submit")->assertOk();
    $sheet = (string) DB::table('results_sheets')->where('offering_id', $test->offering)->value('id');
    $test->actingAs($test->admin)->postJson("{$test->base}/sheets/{$sheet}/defer", ['learner_id' => $test->learners[2], 'reason' => 'Hospital note seen by the board.'])->assertOk();
    $test->actingAs($test->admin)->postJson("{$test->base}/sheets/{$sheet}/decide", ['pass' => true])->assertOk();
    $test->actingAs($test->admin)->postJson("{$test->base}/board/approve", ['period_id' => $test->semester, 'cohort' => $test->programme.':1'])->assertOk();
    $test->actingAs($test->admin)->postJson("{$test->base}/release", ['period_id' => $test->semester, 'sitting' => 'main'])->assertOk();
    app()->forgetScopedInstances();
}

function progressionStudent(object $test, string $learner, string $phone): User
{
    $base = "/api/v1/programmes/{$test->college}";
    $test->actingAs($test->admin)->putJson("{$base}/accounts/{$learner}/phone", ['phone' => $phone])->assertOk();
    $test->actingAs($test->admin)->postJson("{$base}/accounts/codes", ['learners' => [$learner]])->assertOk();
    $user = User::query()->findOrFail(DB::table('programme_student_accounts')->where('learner_id', $learner)->value('user_id'));
    $user->forceFill(['password' => Hash::make('a long pass phrase')])->save();
    DB::table('programme_student_accounts')->where('learner_id', $learner)->update(['set_up_at' => now()]);

    return $user;
}

it('works out the end of the year, lets the board decide, and applies it through Programmes', function (): void {
    progressionCollege($this, 2, 30);
    $q = "programme={$this->programme}&year=1";
    $list = $this->actingAs($this->admin)->getJson("{$this->base}/progression/list?{$q}")->assertOk();
    $rows = collect($list->json('rows'))->keyBy('name');
    expect($rows['Chikondi Banda'])->toMatchArray(['decision' => 'proceed', 'standing' => 'good', 'cgpa' => 3.5])
        ->and($rows['Tiwonge Chirwa'])->toMatchArray(['decision' => 'carry', 'failed_credits' => 15, 'failed' => ['ACC 101'], 'standing' => 'probation'])
        ->and($rows['Mphatso Nkhoma'])->toMatchArray(['decision' => null, 'fallback' => null]);
    $this->actingAs($this->lecturer)->postJson("{$this->base}/progression/decide", ['programme_id' => $this->programme, 'year' => 1])->assertForbidden();
    // A student whose results are not complete has no decision until the board chooses one.
    $this->actingAs($this->admin)->postJson("{$this->base}/progression/decide", ['programme_id' => $this->programme, 'year' => 1])->assertUnprocessable();
    $choose = ['programme_id' => $this->programme, 'year' => 1, 'learner_id' => $this->learners[2]];
    $this->actingAs($this->admin)->postJson("{$this->base}/progression/choose", [...$choose, 'decision' => 'continue', 'reason' => 'One more semester.'])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("{$this->base}/progression/choose", [...$choose, 'decision' => 'repeat_year'])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("{$this->base}/progression/choose", [...$choose, 'decision' => 'repeat_year', 'reason' => 'The deferred exam is sat next year.'])->assertOk();
    $this->actingAs($this->admin)->postJson("{$this->base}/progression/decide", ['programme_id' => $this->programme, 'year' => 1])->assertOk();

    $id = (string) DB::table('results_progression_lists')->where('programme_id', $this->programme)->value('id');
    $this->actingAs($this->lecturer)->postJson("{$this->base}/progression/{$id}/apply")->assertForbidden();
    $this->actingAs($this->admin)->postJson("{$this->base}/progression/{$id}/apply")->assertOk()->assertJsonPath('applied', 3);
    $year = fn (string $learner): int => (int) DB::table('programme_students')->where('learner_id', $learner)->whereNull('ended_at')->value('year_of_study');
    expect($year($this->learners[0]))->toBe(2)->and($year($this->learners[1]))->toBe(2)->and($year($this->learners[2]))->toBe(1)
        ->and(DB::table('programme_carried_courses')->where('learner_id', $this->learners[1])->value('course_id'))->toBe($this->course)
        ->and(DB::table('results_progression_decisions')->count())->toBe(3);

    // Next year: the carried course joins the student's registration as Repeat.
    $now = now();
    $next = Ids::next();
    DB::table('academic_years')->insert(['id' => $next, 'name' => '2027/28', 'starts_on' => '2027-09-06', 'ends_on' => '2028-07-21', 'created_at' => $now, 'updated_at' => $now]);
    $semester = Ids::next();
    DB::table('academic_periods')->insert(['id' => $semester, 'year_id' => $next, 'division_id' => $this->college, 'name' => 'Semester 1', 'position' => 1,
        'starts_on' => '2027-09-06', 'ends_on' => '2027-12-17', 'created_at' => $now, 'updated_at' => $now]);
    $this->travelTo('2027-09-08 10:00:00');
    $base = "/api/v1/programmes/{$this->college}";
    $this->actingAs($this->admin)->postJson("{$base}/offerings/{$semester}/prepare")->assertOk();
    $this->actingAs($this->admin)->putJson("{$base}/registration/{$semester}/window", ['opens_on' => '2027-09-01', 'closes_on' => '2027-09-30',
        'late_until' => '2027-10-05', 'add_drop_until' => '2027-10-10', 'late_fee' => 0, 'fees_share' => 0])->assertOk();
    $this->actingAs($this->admin)->postJson("{$base}/registration/{$semester}/start")->assertOk();
    $kinds = DB::table('programme_registration_courses as rc')->join('programme_registrations as r', 'r.id', '=', 'rc.registration_id')->join('programme_courses as c', 'c.id', '=', 'rc.course_id')
        ->where('r.learner_id', $this->learners[1])->where('r.period_id', $semester)->pluck('rc.kind', 'c.code')->all();
    ksort($kinds);
    expect($kinds)->toBe(['ACC 101' => 'repeat', 'ACC 201' => 'core']);
    $this->travelTo('2026-11-19 10:00:00');

    // The student sees the decision; the record shows it too.
    $student = progressionStudent($this, $this->learners[1], '0991 234 568');
    $mine = $this->actingAs($student)->getJson('/api/v1/student/results')->assertOk();
    expect($mine->json('decisions.0'))->toMatchArray(['decision' => 'carry', 'carried' => ['ACC 101'], 'state' => 'applied'])
        ->and($mine->json('semesters.0'))->toMatchArray(['standing' => 'probation']);
    app()->forgetScopedInstances();
    expect($this->actingAs($this->admin)->getJson("/api/v1/results/learners/{$this->learners[1]}")->json('decisions.0.decision'))->toBe('carry');

    // Transcripts: the student's copy, a request, an official copy issued by the registry.
    $copy = $this->actingAs($student)->get('/api/v1/student/results/transcript')->assertOk();
    expect($copy->headers->get('Content-Type'))->toBe('application/pdf');
    $this->actingAs($student)->postJson('/api/v1/student/results/transcripts', ['for_kind' => 'organisation', 'organisation' => 'Reserve Bank', 'email' => 'no'])->assertUnprocessable();
    $request = $this->actingAs($student)->postJson('/api/v1/student/results/transcripts', ['for_kind' => 'organisation', 'organisation' => 'Reserve Bank',
        'email' => 'hr@example.org'])->assertCreated()->json('id');
    $this->actingAs($this->lecturer)->postJson("{$this->base}/transcripts", ['learner_id' => $this->learners[1], 'request_id' => $request])->assertForbidden();
    $issued = $this->actingAs($this->admin)->postJson("{$this->base}/transcripts", ['learner_id' => $this->learners[1], 'request_id' => $request])->assertCreated()->json('id');
    expect($this->actingAs($this->admin)->getJson("{$this->base}/transcripts")->json('requests.0.issued.reference'))->toStartWith('TR-');
    $this->actingAs($this->admin)->get("{$this->base}/transcripts/{$issued}")->assertOk();
    $this->actingAs($student)->get("/api/v1/student/results/transcripts/{$issued}")->assertOk();
    expect($this->actingAs($student)->getJson('/api/v1/student/results/transcripts')->json('requests.0.issued.id'))->toBe($issued);
});

it('takes a graduation list from the board to conferral, with the class of award from the rules', function (): void {
    progressionCollege($this, 1, 15);
    $list = $this->actingAs($this->admin)->getJson("{$this->base}/graduation/list?programme={$this->programme}")->assertOk();
    $rows = collect($list->json('rows'))->keyBy('name');
    expect($rows['Chikondi Banda'])->toMatchArray(['complete' => true, 'class' => 'Credit', 'credits' => 15])
        ->and($rows['Tiwonge Chirwa'])->toMatchArray(['complete' => false, 'class' => null, 'missing' => ['ACC 101']]);

    $this->actingAs($this->lecturer)->postJson("{$this->base}/graduation/approve", ['programme_id' => $this->programme])->assertForbidden();
    $this->actingAs($this->admin)->postJson("{$this->base}/graduation/approve", ['programme_id' => $this->programme])->assertOk();
    $id = (string) DB::table('results_award_lists')->where('programme_id', $this->programme)->value('id');
    $this->actingAs($this->admin)->postJson("{$this->base}/graduation/{$id}/confirm", ['confirm' => true])->assertOk();
    $this->actingAs($this->admin)->postJson("{$this->base}/graduation/{$id}/confer")->assertUnprocessable();
    $this->actingAs($this->admin)->putJson("{$this->base}/graduation/{$id}/day", ['graduation_on' => '2027-07-24'])->assertOk();
    $this->actingAs($this->admin)->postJson("{$this->base}/graduation/{$id}/confer")->assertOk()->assertJsonPath('conferred', 1);
    expect(DB::table('programme_students')->where('learner_id', $this->learners[0])->whereNull('ended_at')->value('status'))->toBe('completed')
        ->and(DB::table('programme_students')->where('learner_id', $this->learners[1])->whereNull('ended_at')->value('status'))->toBe('active');

    $student = progressionStudent($this, $this->learners[0], '0991 234 567');
    expect($this->actingAs($student)->getJson('/api/v1/student/results')->json('award'))->toMatchArray(['class' => 'Credit', 'state' => 'conferred', 'graduation_on' => '2027-07-24']);
});

it('keeps a saved set of rules, refuses a bad one, and reads classes from it', function (): void {
    progressionCollege($this, 1, 15);
    $classes = ['diploma' => [['name' => 'Merit', 'from' => 3.4], ['name' => 'Pass', 'from' => 2]], 'degree' => [['name' => 'Pass', 'from' => 2]]];
    $body = ['period_id' => $this->semester, 'good_cgpa' => 2, 'carry_credits' => 30, 'max_attempts' => 9, 'time_factor' => 2, 'hold_certificate' => false, 'classes' => $classes];
    $this->actingAs($this->lecturer)->postJson("{$this->base}/setup/rules", $body)->assertForbidden();
    $this->actingAs($this->admin)->postJson("{$this->base}/setup/rules", $body)->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("{$this->base}/setup/rules", [...$body, 'max_attempts' => 3])->assertCreated();
    expect($this->actingAs($this->admin)->getJson("{$this->base}/setup/rules")->json('rules.classes.diploma.0.name'))->toBe('Merit')
        ->and($this->actingAs($this->admin)->getJson("{$this->base}/graduation/list?programme={$this->programme}")->json('rows.0.class'))->toBe('Merit')
        ->and(Rules::classOf(Rules::standard(), 'bachelor', 2.6))->toBe('Lower second');
});
