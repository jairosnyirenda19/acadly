<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Classroom\Contracts\ClassroomPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Classroom (D-104) and the pages of a document drawn as pictures (D-103):
 * a course page with resources and announcements, assignments with their
 * weights, hand-in with withdraw and hand in again, the lock at the
 * deadline, late hand-ins, marking, release, continuous assessment, the
 * learner record and the student portal.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    // Thursday 19 November 2026, 12:00 in the school's timezone.
    $this->travelTo('2026-11-19 10:00:00');
    $this->artisan('acadly:sync')->assertSuccessful();
    foreach (['programmes', 'classroom'] as $module) {
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
    foreach (['Chikondi Banda', 'Thoko Mwale', 'Kondwani Phiri'] as $i => $name) {
        [$first, $surname] = explode(' ', $name);
        $learner = Ids::next();
        $enrolment = Ids::next();
        DB::table('learners')->insert(['id' => $learner, 'acadly_id' => 'AL-5000-000'.$i, 'first_name' => $first, 'surname' => $surname, 'sex' => 'female',
            'date_of_birth' => '2005-01-01', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('enrolments')->insert(['id' => $enrolment, 'learner_id' => $learner, 'status' => 'active', 'admitted_on' => '2026-09-07', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('placements')->insert(['id' => Ids::next(), 'enrolment_id' => $enrolment, 'learner_id' => $learner, 'year_id' => $year, 'division_id' => $this->college,
            'campus_id' => $campus, 'level_code' => 'Y1', 'class_id' => $class, 'starts_on' => '2026-09-07', 'entry_reason' => 'admission', 'created_at' => $now]);
        $this->learners[] = $learner;
    }

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $lecturers = Role::query()->create(['key' => 'class_lecturer_test', 'name' => 'Lecturer']);
    $lecturers->grant([ClassroomPermission::Teach]);
    $this->lecturer = User::factory()->create(['name' => 'Agnes Chilembwe']);
    $this->other = User::factory()->create(['name' => 'Joseph Banda']);
    foreach ([$this->lecturer, $this->other] as $u) {
        RoleAssignment::query()->create(['user_id' => $u->id, 'role_id' => $lecturers->id, 'scope_type' => ScopeType::Division, 'scope_id' => $this->college, 'starts_at' => now()->subMinute()]);
    }

    $base = "/api/v1/programmes/{$this->college}";
    $this->actingAs($this->admin)->putJson("{$base}/faculties", ['faculties' => [['id' => null, 'name' => 'Faculty of Commerce', 'code' => 'COM', 'retired' => false]]])->assertOk();
    $faculty = (string) DB::table('programme_faculties')->where('division_id', $this->college)->value('id');
    $department = $this->actingAs($this->admin)->postJson("{$base}/departments", ['id' => null, 'faculty_id' => $faculty, 'name' => 'Accounting and Finance',
        'code' => 'ACF', 'prefixes' => ['ACC'], 'head_user_id' => null, 'retired' => false])->assertOk()->json('id');
    $course = $this->actingAs($this->admin)->postJson("{$base}/courses", ['id' => null, 'department_id' => $department, 'code' => 'ACC 101', 'title' => 'Financial Accounting I',
        'credits' => 15, 'lecture_hours' => 3, 'tutorial_hours' => 1, 'practical_hours' => 0, 'needs' => [], 'retired' => false])->assertOk()->json('id');
    $programme = $this->actingAs($this->admin)->postJson("{$base}/programmes", ['id' => null, 'department_id' => $department, 'code' => 'CAC',
        'name' => 'Certificate in Accounting', 'award' => 'certificate', 'years' => 1, 'semesters' => 1, 'credits' => 15, 'min_load' => 15, 'max_load' => 45,
        'open' => true, 'retired' => false])->assertOk()->json('id');
    $draft = $this->actingAs($this->admin)->getJson("{$base}/programmes/{$programme}")->json('version.id');
    $this->actingAs($this->admin)->putJson("{$base}/versions/{$draft}", ['groups' => [], 'courses' => [['course_id' => $course, 'year' => 1, 'semester' => 1, 'group' => null]]])->assertOk();
    $this->actingAs($this->admin)->postJson("{$base}/versions/{$draft}/release")->assertOk();
    $this->actingAs($this->admin)->postJson("{$base}/offerings/{$this->semester}/prepare")->assertOk();
    $this->actingAs($this->admin)->putJson("{$base}/registration/{$this->semester}/window", ['opens_on' => '2026-11-01', 'closes_on' => '2026-11-30',
        'late_until' => '2026-12-05', 'add_drop_until' => '2026-12-10', 'late_fee' => 0, 'fees_share' => 0])->assertOk();
    foreach ($this->learners as $learner) {
        $this->actingAs($this->admin)->postJson("{$base}/students", ['learner_id' => $learner, 'programme_id' => $programme, 'entry_year' => 1, 'mode' => 'full_time'])->assertOk();
    }
    $this->actingAs($this->admin)->postJson("{$base}/registration/{$this->semester}/start")->assertOk();
    $this->offering = (string) DB::table('programme_offerings')->where('period_id', $this->semester)->where('course_id', $course)->value('id');
    $this->actingAs($this->admin)->putJson("{$base}/offering/{$this->offering}", ['limit' => null, 'lecturers' => [$this->lecturer->id], 'assistants' => []])->assertOk();
    $this->base = "/api/v1/classroom/{$this->college}";
});

/** A student account, set up, for a learner. */
function classroomStudent(object $test, string $learner, string $phone): User
{
    $base = "/api/v1/programmes/{$test->college}";
    $test->actingAs($test->admin)->putJson("{$base}/accounts/{$learner}/phone", ['phone' => $phone])->assertOk();
    $test->actingAs($test->admin)->postJson("{$base}/accounts/codes", ['learners' => [$learner]])->assertOk();
    $user = User::query()->findOrFail(DB::table('programme_student_accounts')->where('learner_id', $learner)->value('user_id'));
    $user->forceFill(['password' => Hash::make('a long pass phrase')])->save();
    DB::table('programme_student_accounts')->where('learner_id', $learner)->update(['set_up_at' => now()]);

    return $user;
}

function aPdf(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
}

/** A small real picture, so the page worker can draw it. */
function aPng(string $name): UploadedFile
{
    $image = imagecreatetruecolor(40, 30);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 10, 119, 117));
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

it('runs a course page: resources, announcements and weights, for its own lecturer only', function (): void {
    $student = classroomStudent($this, $this->learners[0], '0991 234 567');
    // A lecturer not named on the course may not run it; one with no permission there sees nothing.
    $this->actingAs($this->other)->postJson("{$this->base}/courses/{$this->offering}/announcements", ['title' => 'Hello', 'body' => 'Hello.'])->assertNotFound();
    expect($this->actingAs($this->other)->getJson("{$this->base}/courses")->assertOk()->json('courses'))->toBe([]);

    $this->actingAs($this->lecturer)->post("{$this->base}/courses/{$this->offering}/resources", ['section' => 'Week 11', 'title' => 'Slides', 'file' => aPdf('slides.pdf')],
        ['Accept' => 'application/json'])->assertCreated();
    $this->actingAs($this->lecturer)->postJson("{$this->base}/courses/{$this->offering}/resources", ['section' => 'Week 11', 'title' => 'IAS 7', 'url' => 'ftp://x'])->assertUnprocessable();
    $this->actingAs($this->lecturer)->postJson("{$this->base}/courses/{$this->offering}/resources", ['section' => 'Week 12', 'title' => 'Answers', 'url' => 'https://example.org/answers',
        'shows_from' => '2026-11-26'])->assertCreated();
    $this->actingAs($this->lecturer)->postJson("{$this->base}/courses/{$this->offering}/announcements", ['title' => 'Tutorial moved', 'body' => 'The tutorial is in B14.', 'sms' => true])->assertCreated();
    expect(DB::table('notification_requests')->where('topic_key', 'classroom.announced')->count())->toBe(1);

    $task = fn (array $extra) => $this->actingAs($this->lecturer)->postJson("{$this->base}/courses/{$this->offering}/tasks", [...['kind' => 'assignment', 'title' => 'Cash flows',
        'instructions' => 'Prepare the statement.', 'shows_at' => '2026-11-16 08:00', 'due_at' => '2026-11-21 23:59', 'late_until' => null, 'out_of' => 50, 'weight' => 10,
        'accepts' => ['pdf', 'word'], 'max_files' => 2, 'max_mb' => 20], ...$extra]);
    $task([])->assertCreated();
    $task(['title' => 'Too heavy', 'weight' => 35])->assertUnprocessable()->assertJsonValidationErrors(['weight']);
    $task(['title' => 'Back to front', 'due_at' => '2026-11-10 08:00'])->assertUnprocessable()->assertJsonValidationErrors(['due_at']);

    $page = $this->actingAs($this->lecturer)->getJson("{$this->base}/courses/{$this->offering}")->assertOk()->json();
    expect($page['teaches'])->toBeTrue()->and($page['resources'])->toHaveCount(2)->and($page['weights'])->toEqual(10)->and($page['share'])->toBe(40)
        ->and($page['tasks'][0]['late_until'])->toStartWith('2026-11-28');

    // The student sees the resources already shown, and opens the file.
    $mine = $this->actingAs($student)->getJson("/api/v1/student/classroom/courses/{$this->offering}")->assertOk()->json();
    expect(collect($mine['resources'])->pluck('title')->all())->toBe(['Slides'])->and($mine['announcements'][0]['read'])->toBeFalse();
    $this->actingAs($student)->get('/api/v1/student/classroom/files/'.$mine['resources'][0]['document_id'])->assertOk();
    $this->actingAs($student)->postJson("/api/v1/student/classroom/announcements/{$mine['announcements'][0]['id']}/read")->assertOk();
    // The console stays closed to a student.
    $this->actingAs($student)->getJson("{$this->base}/courses")->assertNotFound();
});

it('takes hand-ins with withdraw and hand in again, locks at the deadline, takes late work, and releases marks together', function (): void {
    $first = classroomStudent($this, $this->learners[0], '0991 234 567');
    $second = classroomStudent($this, $this->learners[1], '0888 765 432');
    $task = $this->actingAs($this->lecturer)->postJson("{$this->base}/courses/{$this->offering}/tasks", ['kind' => 'assignment', 'title' => 'Cash flows', 'instructions' => 'Prepare it.',
        'shows_at' => '2026-11-16 08:00', 'due_at' => '2026-11-21 23:59', 'late_until' => '2026-11-24 23:59', 'out_of' => 50, 'weight' => 10, 'accepts' => ['pdf'],
        'max_files' => 2, 'max_mb' => 20])->assertCreated()->json('id');
    $hand = fn (User $s, array $files) => $this->actingAs($s)->post("/api/v1/student/classroom/tasks/{$task}/hand-in", ['files' => $files], ['Accept' => 'application/json']);

    // A file of a kind the task does not take is refused; a PDF is taken.
    $hand($first, [aPng('photo.png')])->assertUnprocessable();
    $hand($first, [aPdf('draft.pdf')])->assertCreated();
    $hand($first, [aPdf('again.pdf')])->assertUnprocessable();
    $this->actingAs($first)->postJson("/api/v1/student/classroom/tasks/{$task}/withdraw")->assertOk();
    $hand($first, [aPdf('cashflow.pdf')])->assertCreated();
    $mine = $this->actingAs($first)->getJson("/api/v1/student/classroom/tasks/{$task}")->assertOk()->json();
    expect($mine['state'])->toBe('handed_in')->and($mine['files'][0]['name'])->toBe('cashflow.pdf')->and($mine['history'])->toHaveCount(3)->and($mine['can_withdraw'])->toBeTrue();
    // Marking opens at the deadline.
    $this->actingAs($this->lecturer)->postJson("{$this->base}/tasks/{$task}/marks", ['learner_id' => $this->learners[0], 'mark' => 40])->assertUnprocessable();

    // After the deadline: the first is locked; the second hands in late; the third has nothing.
    $this->travelTo('2026-11-22 08:00:00');
    expect($this->actingAs($first)->getJson("/api/v1/student/classroom/tasks/{$task}")->json('state'))->toBe('locked');
    $this->actingAs($first)->postJson("/api/v1/student/classroom/tasks/{$task}/withdraw")->assertUnprocessable();
    $hand($second, [aPdf('late.pdf')])->assertCreated();
    $list = $this->actingAs($this->lecturer)->getJson("{$this->base}/tasks/{$task}")->assertOk()->json();
    $states = collect($list['rows'])->pluck('state', 'learner_id');
    expect($states[$this->learners[0]])->toBe('locked')->and($states[$this->learners[1]])->toBe('late')->and($states[$this->learners[2]])->toBe('late_open')
        ->and($list['counts']['to_mark'])->toBe(2);

    // Marking, with feedback and a marked file; release waits for the late day and for every mark.
    $this->actingAs($this->lecturer)->post("{$this->base}/tasks/{$task}/marks", ['learner_id' => $this->learners[0], 'mark' => 41, 'feedback' => 'Clear layout.',
        'file' => aPdf('cashflow-marked.pdf')], ['Accept' => 'application/json'])->assertCreated();
    $this->actingAs($this->lecturer)->postJson("{$this->base}/tasks/{$task}/marks", ['learner_id' => $this->learners[2], 'mark' => 10])->assertUnprocessable();
    $this->actingAs($this->lecturer)->postJson("{$this->base}/tasks/{$task}/release")->assertUnprocessable();
    $this->travelTo('2026-11-25 08:00:00');
    $this->actingAs($this->lecturer)->postJson("{$this->base}/tasks/{$task}/release")->assertUnprocessable()->assertJsonValidationErrors(['release']);
    $work = $this->actingAs($this->lecturer)->getJson("{$this->base}/tasks/{$task}/work/{$this->learners[1]}")->assertOk()->json();
    expect($work['state'])->toBe('late')->and($work['files'][0]['name'])->toBe('late.pdf')->and($work['position']['of'])->toBe(2);
    $this->actingAs($this->lecturer)->postJson("{$this->base}/tasks/{$task}/marks", ['learner_id' => $this->learners[1], 'mark' => 30, 'feedback' => 'Late; working changes wrong.'])->assertCreated();
    expect($this->actingAs($first)->getJson("/api/v1/student/classroom/tasks/{$task}")->json('mark'))->toBeNull();
    $this->actingAs($this->lecturer)->postJson("{$this->base}/tasks/{$task}/release")->assertOk();

    // Released: the student sees the mark, the feedback and the marked file; a change needs a reason and is a new row.
    $mine = $this->actingAs($first)->getJson("/api/v1/student/classroom/tasks/{$task}")->json();
    expect($mine['mark']['mark'])->toEqual(41)->and($mine['mark']['feedback'])->toBe('Clear layout.')->and($mine['mark']['files'][0]['name'])->toBe('cashflow-marked.pdf');
    $this->actingAs($first)->get('/api/v1/student/classroom/files/'.$mine['mark']['files'][0]['id'])->assertOk();
    $this->actingAs($this->lecturer)->postJson("{$this->base}/tasks/{$task}/marks", ['learner_id' => $this->learners[0], 'mark' => 43])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->actingAs($this->lecturer)->postJson("{$this->base}/tasks/{$task}/marks", ['learner_id' => $this->learners[0], 'mark' => 43, 'reason' => 'Added up wrongly.'])->assertCreated();
    expect($this->actingAs($first)->getJson("/api/v1/student/classroom/tasks/{$task}")->json('mark'))->toMatchArray(['mark' => 43, 'was' => 41]);
    // Another student's hand-in stays closed to a student.
    $theirs = DB::table('documents')->where('owner_type', 'classroom_hand_in')->orderByDesc('created_at')->value('id');
    $this->actingAs($first)->get("/api/v1/student/classroom/files/{$theirs}")->assertNotFound();

    // Continuous assessment, the learner record and the student's course page.
    $ca = $this->actingAs($this->lecturer)->getJson("{$this->base}/courses/{$this->offering}/assessment")->assertOk()->json();
    $totals = collect($ca['rows'])->pluck('total', 'learner_id');
    expect($totals[$this->learners[0]])->toEqual(8.6)->and($totals[$this->learners[1]])->toEqual(6)->and($totals[$this->learners[2]])->toEqual(0);
    $tab = $this->actingAs($this->admin)->getJson("/api/v1/classroom/learners/{$this->learners[2]}")->assertOk()->json();
    expect($tab['missing'])->toBe(1)->and($tab['courses'][0]['code'])->toBe('ACC 101');
    expect($this->actingAs($first)->getJson("/api/v1/student/classroom/courses/{$this->offering}")->json('ca'))->toEqual(8.6);

    // Hand-ins and marks cannot be changed or removed.
    DB::beginTransaction();
    expect(fn () => DB::table('classroom_marks')->where('task_id', $task)->delete())->toThrow(Exception::class);
    DB::rollBack();
});

it('takes nothing late for an exam paper', function (): void {
    $student = classroomStudent($this, $this->learners[0], '0991 234 567');
    $exam = $this->actingAs($this->lecturer)->postJson("{$this->base}/courses/{$this->offering}/tasks", ['kind' => 'exam', 'title' => 'Mid-semester test', 'instructions' => '',
        'shows_at' => '2026-11-19 11:00', 'due_at' => '2026-11-19 14:00', 'late_until' => '2026-11-25 14:00', 'out_of' => 100, 'weight' => 20, 'accepts' => ['pdf', 'images'],
        'max_files' => 5, 'max_mb' => 20])->assertCreated()->json('id');
    expect($this->actingAs($student)->getJson("/api/v1/student/classroom/tasks/{$exam}")->json('task.late_until'))->toBeNull();
    $this->travelTo('2026-11-19 12:30:00');
    $this->actingAs($student)->post("/api/v1/student/classroom/tasks/{$exam}/hand-in", ['files' => [aPng('page1.png')]], ['Accept' => 'application/json'])->assertUnprocessable();
    expect($this->actingAs($student)->getJson("/api/v1/student/classroom/tasks/{$exam}")->json('state'))->toBe('missing');
});

it('draws the pages of a resource, and refuses a person who may not open it', function (): void {
    config(['acadly.documents.pages' => true]);
    $this->actingAs($this->lecturer)->post("{$this->base}/courses/{$this->offering}/resources", ['section' => 'Week 11', 'title' => 'Board photo', 'file' => aPng('board.png')],
        ['Accept' => 'application/json'])->assertCreated();
    $document = (string) DB::table('classroom_resources')->where('title', 'Board photo')->value('document_id');
    $pages = $this->actingAs($this->lecturer)->getJson("/api/v1/documents/{$document}/pages")->assertOk()->json();
    expect($pages['status'])->toBe('ready')->and($pages['pages'])->toHaveCount(1)->and($pages['name'])->toBe('board.png');
    $this->actingAs($this->lecturer)->get("/api/v1/documents/{$document}/pages/1")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->actingAs($this->lecturer)->get("/api/v1/documents/{$document}/pages/2")->assertNotFound();
    // A lecturer of another course, with no sight of the division's courses, may not open it.
    $this->actingAs($this->other)->getJson("/api/v1/documents/{$document}/pages")->assertNotFound();
    $this->actingAs($this->other)->get("/api/v1/documents/{$document}/pages/1")->assertNotFound();
});
