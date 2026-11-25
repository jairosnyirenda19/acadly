<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\Actions\SendSignInCode;
use Acadly\Kernel\Identity\Actions\SignIn;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/*
 * The student portal (D-101): a student account set up with a code, sign-in
 * with the student number, the student portal only, the student's own
 * registration, courses and slip, and News to the audiences chosen.
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

/** A student account set up for a learner, with a known password. */
function studentAccount(object $test, string $learner, string $phone = '0991 234 567'): User
{
    $test->actingAs($test->admin)->putJson("{$test->base}/accounts/{$learner}/phone", ['phone' => $phone])->assertOk();
    $test->actingAs($test->admin)->postJson("{$test->base}/accounts/codes", ['learners' => [$learner]])->assertOk()->assertJsonPath('sent', 1);
    $user = User::query()->findOrFail(DB::table('programme_student_accounts')->where('learner_id', $learner)->value('user_id'));
    $user->forceFill(['password' => Hash::make('a long pass phrase')])->save();
    DB::table('programme_student_accounts')->where('learner_id', $learner)->update(['set_up_at' => now()]);

    return $user;
}

it('sets up a student account with a code, signs in with the student number, and reaches only the student portal', function (): void {
    ($this->placeAll)([$this->learners[0], $this->learners[1]]);
    $this->actingAs($this->admin)->putJson("{$this->base}/accounts/{$this->learners[0]}/phone", ['phone' => '0991 234 567'])->assertOk();
    $this->actingAs($this->admin)->postJson("{$this->base}/accounts/codes", ['learners' => [$this->learners[0], $this->learners[1]]])->assertOk()
        ->assertJson(['sent' => 1, 'no_phone' => 1]);
    $accounts = $this->actingAs($this->admin)->getJson("{$this->base}/accounts")->assertOk()->json();
    expect($accounts['totals'])->toMatchArray(['students' => 2, 'code_sent' => 1, 'none' => 1]);

    // The code goes by text message; the test knows it by setting its hash.
    $phone = (string) DB::table('programme_student_accounts')->where('learner_id', $this->learners[0])->value('phone');
    DB::table('sign_in_codes')->where('purpose', 'student_set_up')->update(['code_hash' => SendSignInCode::hash($phone, '482913')]);
    auth()->forgetGuards();
    $this->postJson('/api/v1/student/set-up', ['number' => 'AL-3000-0000', 'code' => '111111', 'password' => 'a long pass phrase'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    // The wrong try is kept, though the request was refused.
    expect(DB::table('sign_in_codes')->where('purpose', 'student_set_up')->value('attempts'))->toBe(1);
    $this->postJson('/api/v1/student/set-up', ['number' => 'AL-3000-0000', 'code' => '482913', 'password' => 'Chikondi long'])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    $this->postJson('/api/v1/student/set-up', ['number' => 'al-3000-0000', 'code' => '482913', 'password' => 'a long pass phrase'])->assertOk()->assertJsonPath('signed_in', true);
    expect(DB::table('audit_events')->where('action', 'programmes.student_account_set_up')->count())->toBe(1);

    // Signing in with the student number and the password; the account opens only the student portal.
    $student = app(SignIn::class)->handle('AL-3000-0000', 'a long pass phrase', '127.0.0.1');
    $this->actingAs($student)->getJson('/api/v1/student/me')->assertOk()->assertJsonPath('number', 'AL-3000-0000')->assertJsonPath('programme.code', 'CAC');
    $this->actingAs($student)->getJson('/api/v1/learners')->assertNotFound();
    $this->actingAs($student)->getJson("{$this->base}/students")->assertNotFound();
    $this->actingAs($student)->getJson('/api/v1/today')->assertNotFound();

    // Suspended: sign-in stops at once; restored with a reason.
    $this->actingAs($this->admin)->postJson("{$this->base}/accounts/{$this->learners[0]}/suspend", ['reason' => ''])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("{$this->base}/accounts/{$this->learners[0]}/suspend", ['reason' => 'Fees dispute under review.'])->assertOk();
    expect(fn () => app(SignIn::class)->handle('AL-3000-0000', 'a long pass phrase', '127.0.0.2'))->toThrow(ValidationException::class);
    $this->actingAs($this->admin)->postJson("{$this->base}/accounts/{$this->learners[0]}/suspend", ['reason' => 'Resolved.', 'restore' => true])->assertOk();
    expect(app(SignIn::class)->handle('AL-3000-0000', 'a long pass phrase', '127.0.0.3')->id)->toBe($student->id);
});

it('lets a student choose and send their own registration, read their courses and get the slip', function (): void {
    ($this->placeAll)([$this->learners[0], $this->learners[1]]);
    // Opened one by one, each registration waits for the student to send it.
    foreach ([$this->learners[0], $this->learners[1]] as $learner) {
        $this->actingAs($this->admin)->postJson("{$this->base}/registration/{$this->semesters[1]}/open", ['learner_id' => $learner])->assertOk();
    }
    $student = studentAccount($this, $this->learners[0]);
    $other = studentAccount($this, $this->learners[1], '0888 765 432');

    $mine = $this->actingAs($student)->getJson('/api/v1/student/registration')->assertOk()->json('registration');
    expect($mine['state'])->toBe('draft')->and($mine['editable'])->toBeTrue()
        ->and(collect($mine['steps'])->pluck('step')->all())->toContain('submit_own')
        ->and($mine['student']['code'])->toBe('AL-3000-0000');

    $courses = [($this->offering)($this->semesters[1], 'ACC 101'), ($this->offering)($this->semesters[1], 'LAW 101')];
    $this->actingAs($student)->putJson('/api/v1/student/registration/courses', ['offerings' => $courses])->assertOk();
    $this->actingAs($student)->postJson('/api/v1/student/registration/submit')->assertOk();
    // The registry's own routes stay closed to a student, for their registration and anyone else's.
    $theirs = (string) DB::table('programme_registrations')->where('learner_id', $this->learners[1])->value('id');
    $this->actingAs($student)->postJson("{$this->base}/registrations/{$theirs}/submit")->assertNotFound();
    expect($this->actingAs($other)->getJson('/api/v1/student/registration')->json('registration.state'))->toBe('draft');

    $this->actingAs($this->admin)->postJson("{$this->base}/registration/{$this->semesters[1]}/approve", ['ids' => [$mine['id']]])->assertOk();
    expect($this->actingAs($student)->getJson('/api/v1/student/registration')->json('registration.state'))->toBe('registered');
    $this->actingAs($student)->get('/api/v1/student/registration/slip')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $mineCourses = $this->actingAs($student)->getJson('/api/v1/student/courses')->assertOk()->json();
    expect(array_column($mineCourses['courses'], 'code'))->toBe(['ACC 101', 'LAW 101'])->and($mineCourses['credits'])->toBe(30);
    expect($this->actingAs($student)->getJson('/api/v1/student/fees')->assertOk()->json('fees'))->toBeNull();
});

it('publishes news to an audience and shows each student only the news meant for them', function (): void {
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'news', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    app()->forgetScopedInstances();
    ($this->placeAll)([$this->learners[0]]);
    $student = studentAccount($this, $this->learners[0]);
    $post = fn (string $title, string $audience, ?string $id = null, ?int $year = null): string => (string) $this->actingAs($this->admin)->postJson("/api/v1/news/{$this->college}",
        ['title' => $title, 'body' => "About {$title}.", 'audience' => $audience, 'audience_id' => $id, 'year' => $year])->assertCreated()->json('id');
    $all = $post('Exam timetable', 'everyone');
    $mine = $post('Accounting society', 'programme', $this->programme);
    $post('Year 2 field trip', 'year', null, 2);
    $staff = $post('Staff meeting', 'staff');
    $this->actingAs($this->admin)->postJson("/api/v1/news/{$this->college}", ['title' => 'No one', 'body' => 'x', 'audience' => 'programme'])->assertUnprocessable();

    $seen = $this->actingAs($student)->getJson('/api/v1/student/news')->assertOk()->json('posts');
    expect(array_column($seen, 'title'))->toEqualCanonicalizing(['Exam timetable', 'Accounting society']);
    $this->actingAs($student)->getJson("/api/v1/student/news/{$staff}")->assertNotFound();
    $this->actingAs($student)->getJson("/api/v1/student/news/{$mine}")->assertOk()->assertJsonPath('post.title', 'Accounting society');
    $this->actingAs($student)->getJson("/api/v1/news/{$this->college}")->assertNotFound();

    // Those who publish see how many have read each, never who.
    $board = collect($this->actingAs($this->admin)->getJson("/api/v1/news/{$this->college}")->assertOk()->json('posts'))->keyBy('id');
    expect($board[$mine]['seen'])->toBe(2)->and($board[$all]['seen'])->toBe(1);

    // A change is a new version; withdrawing needs a reason and hides it.
    $this->actingAs($this->admin)->putJson("/api/v1/news/posts/{$all}", ['title' => 'Exam timetable (changed)', 'body' => 'Rooms changed.', 'audience' => 'everyone'])->assertOk();
    expect($this->actingAs($student)->getJson("/api/v1/student/news/{$all}")->json('post'))->toMatchArray(['title' => 'Exam timetable (changed)', 'updated' => true])
        ->and(DB::table('news_posts')->where('root_id', $all)->count())->toBe(2);
    $this->actingAs($this->admin)->postJson("/api/v1/news/posts/{$all}/withdraw", ['reason' => ''])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("/api/v1/news/posts/{$all}/withdraw", ['reason' => 'Posted in error.'])->assertOk();
    expect(array_column($this->actingAs($student)->getJson('/api/v1/student/news')->json('posts'), 'title'))->toBe(['Accounting society']);
});
