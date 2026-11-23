<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Classroom\Contracts\ClassroomPermission;
use Acadly\Modules\Results\Application\Scales;
use Acadly\Modules\Results\Application\Sheets;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Results (D-105): the course mark from continuous assessment and the exam,
 * the attendance bar, entry, moderation, the examiners' board, release,
 * GPAs, the slip, a change after release, deferred exams and the
 * supplementary sitting, the learner record and the student portal.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-19 10:00:00');
    $this->artisan('acadly:sync')->assertSuccessful();
    foreach (['programmes', 'classroom', 'lectures', 'results'] as $module) {
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
    foreach (['Chikondi Banda', 'Thoko Mwale', 'Dalitso Mwenda'] as $i => $name) {
        [$first, $surname] = explode(' ', $name);
        $learner = Ids::next();
        $enrolment = Ids::next();
        DB::table('learners')->insert(['id' => $learner, 'acadly_id' => 'AL-6000-000'.$i, 'first_name' => $first, 'surname' => $surname, 'sex' => 'female',
            'date_of_birth' => '2005-01-01', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('enrolments')->insert(['id' => $enrolment, 'learner_id' => $learner, 'status' => 'active', 'admitted_on' => '2026-09-07', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('placements')->insert(['id' => Ids::next(), 'enrolment_id' => $enrolment, 'learner_id' => $learner, 'year_id' => $year, 'division_id' => $this->college,
            'campus_id' => $campus, 'level_code' => 'Y1', 'class_id' => $class, 'starts_on' => '2026-09-07', 'entry_reason' => 'admission', 'created_at' => $now]);
        $this->learners[] = $learner;
    }

    $this->admin = User::factory()->create(['name' => 'Grace Kamanga']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $lecturers = Role::query()->create(['key' => 'results_lecturer_test', 'name' => 'Lecturer']);
    $lecturers->grant([ClassroomPermission::Teach, ResultsPermission::Enter]);
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
    $this->programme = $this->actingAs($this->admin)->postJson("{$base}/programmes", ['id' => null, 'department_id' => $department, 'code' => 'DAC',
        'name' => 'Diploma in Accounting', 'award' => 'diploma', 'years' => 1, 'semesters' => 1, 'credits' => 15, 'min_load' => 15, 'max_load' => 45,
        'open' => true, 'retired' => false])->assertOk()->json('id');
    $draft = $this->actingAs($this->admin)->getJson("{$base}/programmes/{$this->programme}")->json('version.id');
    $this->actingAs($this->admin)->putJson("{$base}/versions/{$draft}", ['groups' => [], 'courses' => [['course_id' => $course, 'year' => 1, 'semester' => 1, 'group' => null]]])->assertOk();
    $this->actingAs($this->admin)->postJson("{$base}/versions/{$draft}/release")->assertOk();
    $this->actingAs($this->admin)->postJson("{$base}/offerings/{$this->semester}/prepare")->assertOk();
    $this->actingAs($this->admin)->putJson("{$base}/registration/{$this->semester}/window", ['opens_on' => '2026-11-01', 'closes_on' => '2026-11-30',
        'late_until' => '2026-12-05', 'add_drop_until' => '2026-12-10', 'late_fee' => 0, 'fees_share' => 0])->assertOk();
    foreach ($this->learners as $learner) {
        $this->actingAs($this->admin)->postJson("{$base}/students", ['learner_id' => $learner, 'programme_id' => $this->programme, 'entry_year' => 1, 'mode' => 'full_time'])->assertOk();
    }
    $this->actingAs($this->admin)->postJson("{$base}/registration/{$this->semester}/start")->assertOk();
    $this->offering = (string) DB::table('programme_offerings')->where('period_id', $this->semester)->where('course_id', $course)->value('id');
    $this->actingAs($this->admin)->putJson("{$base}/offering/{$this->offering}", ['limit' => null, 'lecturers' => [$this->lecturer->id], 'assistants' => []])->assertOk();

    // Continuous assessment: one released task worth the whole 40%. Marks 30, 20 and 10 out of 40.
    $task = Ids::next();
    DB::table('classroom_tasks')->insert(['id' => $task, 'division_id' => $this->college, 'period_id' => $this->semester, 'offering_id' => $this->offering, 'kind' => 'assignment',
        'title' => 'Assignment 1', 'instructions' => 'Answer every question.', 'shows_at' => '2026-10-01 08:00:00', 'due_at' => '2026-10-15 17:00:00', 'late_until' => null,
        'out_of' => 40, 'weight' => 40, 'accepts' => '[]', 'max_files' => 5, 'max_mb' => 20, 'released_at' => '2026-10-20 08:00:00', 'released_by' => $this->lecturer->id,
        'created_by' => $this->lecturer->id, 'created_at' => $now, 'updated_at' => $now]);
    foreach ([30, 20, 10] as $i => $mark) {
        DB::table('classroom_marks')->insert(['id' => Ids::next(), 'task_id' => $task, 'learner_id' => $this->learners[$i], 'mark' => $mark, 'feedback' => null, 'reason' => null,
            'by_user_id' => $this->lecturer->id, 'created_at' => '2026-10-19 08:00:00']);
    }

    // Ten closed lectures: attended 10, 9 and 7. The third student is below the 80% bar.
    $room = Ids::next();
    DB::table('lecture_rooms')->insert(['id' => $room, 'division_id' => $this->college, 'code' => 'B14', 'name' => 'Room B14', 'seats' => 60, 'kind' => 'room', 'created_at' => $now, 'updated_at' => $now]);
    $slot = Ids::next();
    DB::table('lecture_slots')->insert(['id' => $slot, 'division_id' => $this->college, 'period_id' => $this->semester, 'offering_id' => $this->offering, 'kind' => 'lecture',
        'weekday' => 1, 'starts' => '08:00', 'ends' => '10:00', 'room_id' => $room, 'lecturer_id' => $this->lecturer->id, 'created_at' => $now, 'updated_at' => $now]);
    for ($n = 0; $n < 10; $n++) {
        $day = now()->subWeeks(10 - $n)->toDateString();
        $session = Ids::next();
        DB::table('lecture_sessions')->insert(['id' => $session, 'division_id' => $this->college, 'slot_id' => $slot, 'day' => $day, 'held_on' => $day, 'ends' => '10:00',
            'offering_id' => $this->offering, 'opened_by' => $this->lecturer->id, 'opened_at' => $day.' 08:00:00', 'scan_until' => $day.' 08:15:00', 'secret' => str_repeat('a', 64),
            'closed_at' => $day.' 10:00:00', 'closed_by' => $this->lecturer->id]);
        foreach ([10, 9, 7] as $i => $attended) {
            DB::table('lecture_marks')->insert(['id' => Ids::next(), 'session_id' => $session, 'learner_id' => $this->learners[$i], 'mark' => $n < $attended ? 'present' : 'absent',
                'source' => 'close', 'location' => null, 'reason' => null, 'by_user_id' => null, 'created_at' => $day.' 10:00:00']);
        }
    }

    $this->base = "/api/v1/results/{$this->college}";
    $this->cohort = $this->programme.':1';
});

/** A student account, set up, for a learner. */
function resultsStudent(object $test, string $learner, string $phone): User
{
    $base = "/api/v1/programmes/{$test->college}";
    $test->actingAs($test->admin)->putJson("{$base}/accounts/{$learner}/phone", ['phone' => $phone])->assertOk();
    $test->actingAs($test->admin)->postJson("{$base}/accounts/codes", ['learners' => [$learner]])->assertOk();
    $user = User::query()->findOrFail(DB::table('programme_student_accounts')->where('learner_id', $learner)->value('user_id'));
    $user->forceFill(['password' => Hash::make('a long pass phrase')])->save();
    DB::table('programme_student_accounts')->where('learner_id', $learner)->update(['set_up_at' => now()]);

    return $user;
}

/** Moderates and approves the course's sheet of a sitting. */
function resultsThroughBoard(object $test, string $sitting): string
{
    $sheet = (string) DB::table('results_sheets')->where('offering_id', $test->offering)->where('sitting', $sitting)->value('id');
    $test->actingAs($test->admin)->postJson("{$test->base}/sheets/{$sheet}/decide", ['pass' => true])->assertOk();
    $test->actingAs($test->admin)->postJson("{$test->base}/board/approve", ['period_id' => $test->semester, 'cohort' => $test->cohort])->assertOk()->assertJsonPath('approved', 1);

    return $sheet;
}

it('works out a course mark, a grade and a GPA the standard way', function (): void {
    $scale = Scales::standard();
    expect(Scales::whole(30.4 + 72 * 0.6))->toBe(74)
        ->and(Scales::whole(64.5))->toBe(65)
        ->and(Scales::grade($scale, 74)['grade'])->toBe('B')
        ->and(Scales::grade($scale, 75)['grade'])->toBe('B+')
        ->and(Scales::grade($scale, 49)['grade'])->toBe('F')
        ->and(Scales::gpa([['points' => 3.0, 'credits' => 15], ['points' => 4.0, 'credits' => 10], ['points' => 2.5, 'credits' => 10], ['points' => 1.0, 'credits' => 15], ['points' => 3.5, 'credits' => 10]]))->toBe(2.67);

    $line = ['learner_id' => 'x', 'name' => '', 'number' => '', 'ca' => 20.0, 'share' => 40, 'held' => 0, 'attended' => 0, 'rate' => null, 'barred' => false, 'lifted' => null,
        'exam' => 62.0, 'absence' => null, 'mark' => null, 'grade' => null, 'points' => null, 'result' => 'missing', 'kind' => 'supplementary', 'changed' => false];
    // A supplementary pass is recorded at the pass mark, whatever the exam mark.
    expect(Sheets::work($line, $scale))->toMatchArray(['mark' => 50, 'grade' => 'D', 'result' => 'pass'])
        ->and(Sheets::work([...$line, 'exam' => 40.0], $scale))->toMatchArray(['grade' => 'F', 'result' => 'repeat'])
        ->and(Sheets::work([...$line, 'kind' => 'deferred'], $scale))->toMatchArray(['mark' => 57, 'grade' => 'D', 'result' => 'pass']);
});

it('takes a course from exam marks to release: the bar, moderation, the board, GPAs and the slip', function (): void {
    $student = resultsStudent($this, $this->learners[0], '0991 234 567');
    resultsStudent($this, $this->learners[2], '0991 234 569');

    $sheet = $this->actingAs($this->lecturer)->getJson("{$this->base}/courses/{$this->offering}")->assertOk();
    expect($sheet->json('share'))->toBe(40)
        ->and($sheet->json('lines.0'))->toMatchArray(['name' => 'Chikondi Banda', 'ca' => 30, 'rate' => 100, 'barred' => false])
        ->and(collect($sheet->json('lines'))->firstWhere('name', 'Dalitso Mwenda'))->toMatchArray(['rate' => 70, 'barred' => true, 'result' => 'barred']);
    $bar = $this->actingAs($this->admin)->getJson("{$this->base}/bar")->assertOk();
    expect($bar->json('rows'))->toHaveCount(1)->and($bar->json('rows.0.name'))->toBe('Dalitso Mwenda');

    // Only the course's own lecturer enters its marks.
    $this->actingAs($this->other)->putJson("{$this->base}/courses/{$this->offering}/marks", ['marks' => []])->assertNotFound();
    $this->actingAs($this->lecturer)->putJson("{$this->base}/courses/{$this->offering}/marks", ['marks' => [['learner_id' => $this->learners[0], 'exam' => 101]]])->assertUnprocessable();
    $this->actingAs($this->lecturer)->putJson("{$this->base}/courses/{$this->offering}/marks", ['exam_on' => '2026-12-07', 'marks' => [
        ['learner_id' => $this->learners[0], 'exam' => 75],
    ]])->assertOk()->assertJsonPath('saved', 1);
    // The sheet goes on only with every mark in.
    $this->actingAs($this->lecturer)->postJson("{$this->base}/courses/{$this->offering}/submit")->assertUnprocessable();
    $this->actingAs($this->lecturer)->putJson("{$this->base}/courses/{$this->offering}/marks", ['marks' => [
        ['learner_id' => $this->learners[0], 'exam' => 75], ['learner_id' => $this->learners[1], 'exam' => 40],
    ]])->assertOk()->assertJsonPath('saved', 1);
    $this->actingAs($this->lecturer)->postJson("{$this->base}/courses/{$this->offering}/submit")->assertOk();
    $this->actingAs($this->lecturer)->putJson("{$this->base}/courses/{$this->offering}/marks", ['marks' => [['learner_id' => $this->learners[1], 'exam' => 60]]])->assertUnprocessable();

    // Moderation: a change needs a reason; the lecturer may not moderate.
    $id = (string) DB::table('results_sheets')->where('offering_id', $this->offering)->value('id');
    $this->actingAs($this->lecturer)->postJson("{$this->base}/sheets/{$id}/moderation", ['changes' => [['learner_id' => $this->learners[1], 'exam' => 50, 'reason' => 'Rechecked.']]])->assertForbidden();
    $this->actingAs($this->admin)->postJson("{$this->base}/sheets/{$id}/moderation", ['changes' => [['learner_id' => $this->learners[1], 'exam' => 50, 'reason' => '']]])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("{$this->base}/sheets/{$id}/moderation", ['changes' => [['learner_id' => $this->learners[1], 'exam' => 50, 'reason' => 'Question 4 was added up wrongly.']]])->assertOk();
    $moderated = $this->actingAs($this->admin)->getJson("{$this->base}/courses/{$this->offering}")->assertOk();
    expect(collect($moderated->json('lines'))->firstWhere('name', 'Thoko Mwale'))->toMatchArray(['mark' => 50, 'grade' => 'D', 'result' => 'pass', 'changed' => true])
        ->and($moderated->json('spread.bands.0'))->toBe(['grade' => 'A', 'count' => 0]);
    resultsThroughBoard($this, 'main');

    // The board's list: grades, GPAs and the decision for each student.
    $cohort = $this->actingAs($this->admin)->getJson("{$this->base}/board/cohort?cohort={$this->cohort}")->assertOk();
    $byName = collect($cohort->json('students'))->keyBy('name');
    expect($byName['Chikondi Banda'])->toMatchArray(['gpa' => 3.5, 'decision' => 'pass'])
        ->and($byName['Thoko Mwale'])->toMatchArray(['gpa' => 1, 'decision' => 'pass'])
        ->and($byName['Dalitso Mwenda'])->toMatchArray(['gpa' => 0, 'decision' => 'supplementary']);

    // Nothing shows to a student before release.
    expect($this->actingAs($student)->getJson('/api/v1/student/results')->assertOk()->json('semesters'))->toBe([]);
    $this->actingAs($this->lecturer)->postJson("{$this->base}/release", ['period_id' => $this->semester, 'sitting' => 'main', 'sms' => true])->assertForbidden();
    $this->actingAs($this->admin)->postJson("{$this->base}/release", ['period_id' => $this->semester, 'sitting' => 'main', 'sms' => true])->assertOk()->assertJsonPath('released', 1);
    expect(DB::table('notification_requests')->where('topic_key', 'results.released')->count())->toBe(1);

    $mine = $this->actingAs($student)->getJson('/api/v1/student/results')->assertOk();
    expect($mine->json('semesters.0.gpa'))->toEqual(3.5)
        ->and($mine->json('cumulative'))->toEqual(3.5)
        ->and($mine->json('semesters.0.courses.0'))->toMatchArray(['code' => 'ACC 101', 'mark' => 75, 'grade' => 'B+', 'result' => 'pass']);
    $course = $this->actingAs($student)->getJson("/api/v1/student/results/courses/{$this->offering}")->assertOk();
    expect($course->json('parts.0'))->toMatchArray(['title' => 'Assignment 1', 'earned' => 30])->and($course->json('line.exam'))->toEqual(75);
    $slip = $this->actingAs($student)->get("/api/v1/student/results/slip?period={$this->semester}")->assertOk();
    expect($slip->headers->get('Content-Type'))->toBe('application/pdf');

    // A change after release is a board decision with a reason, and a new row.
    $this->actingAs($this->admin)->postJson("{$this->base}/sheets/{$id}/correct", ['learner_id' => $this->learners[1], 'exam' => 60])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("{$this->base}/sheets/{$id}/correct", ['learner_id' => $this->learners[1], 'exam' => 60, 'reason' => 'Board, 4 December: script remarked.'])->assertOk();
    expect(DB::table('results_marks')->where('learner_id', $this->learners[1])->count())->toBe(3);

    // The supplementary sitting: the barred student sits; a pass is recorded at 50, D.
    $this->actingAs($this->admin)->postJson("{$this->base}/supplementary", ['period_id' => $this->semester, 'exam_on' => '2027-01-11'])->assertOk()->assertJsonPath('opened', 1);
    $supp = $this->actingAs($this->lecturer)->getJson("{$this->base}/courses/{$this->offering}?sitting=supplementary")->assertOk();
    expect(array_column($supp->json('lines'), 'name'))->toBe(['Dalitso Mwenda']);
    // Until the supplementary paper is marked, the main sitting's result stays in force.
    expect($this->actingAs($this->admin)->getJson("/api/v1/results/learners/{$this->learners[2]}")->json('semesters.0.courses.0'))->toMatchArray(['result' => 'barred', 'sitting' => 'main']);
    $this->actingAs($this->lecturer)->putJson("{$this->base}/courses/{$this->offering}/marks", ['sitting' => 'supplementary', 'marks' => [['learner_id' => $this->learners[2], 'exam' => 62]]])->assertOk();
    $this->actingAs($this->lecturer)->postJson("{$this->base}/courses/{$this->offering}/submit", ['sitting' => 'supplementary'])->assertOk();
    resultsThroughBoard($this, 'supplementary');
    $this->actingAs($this->admin)->postJson("{$this->base}/release", ['period_id' => $this->semester, 'sitting' => 'supplementary', 'sms' => false])->assertOk();

    // The record part keeps what it worked out for one request; start afresh as a new request would.
    app()->forgetScopedInstances();
    $record = $this->actingAs($this->admin)->getJson("/api/v1/results/learners/{$this->learners[2]}")->assertOk();
    expect($record->json('semesters.0.courses.0'))->toMatchArray(['mark' => 50, 'grade' => 'D', 'result' => 'pass', 'sitting' => 'supplementary'])
        ->and($record->json('open'))->toBe(0);
});

it('lifts a bar with a reason, and defers an exam the board accepts the absence for', function (): void {
    $this->actingAs($this->lecturer)->postJson("{$this->base}/courses/{$this->offering}/bar/{$this->learners[2]}/lift", ['reason' => 'Medical note.'])->assertForbidden();
    $this->actingAs($this->admin)->postJson("{$this->base}/courses/{$this->offering}/bar/{$this->learners[2]}/lift", ['reason' => 'Admitted to hospital for two weeks; note seen.'])->assertOk();
    $this->actingAs($this->admin)->postJson("{$this->base}/courses/{$this->offering}/bar/{$this->learners[2]}/lift", ['reason' => 'Again.'])->assertUnprocessable();
    $line = collect($this->actingAs($this->lecturer)->getJson("{$this->base}/courses/{$this->offering}")->json('lines'))->firstWhere('name', 'Dalitso Mwenda');
    expect($line)->toMatchArray(['barred' => false, 'result' => 'missing'])->and($line['lifted'])->toContain('hospital');

    $this->actingAs($this->lecturer)->putJson("{$this->base}/courses/{$this->offering}/marks", ['marks' => [
        ['learner_id' => $this->learners[0], 'exam' => 70], ['learner_id' => $this->learners[1], 'absent' => true], ['learner_id' => $this->learners[2], 'exam' => 55],
    ]])->assertOk();
    $this->actingAs($this->lecturer)->postJson("{$this->base}/courses/{$this->offering}/submit")->assertOk();
    $id = (string) DB::table('results_sheets')->where('offering_id', $this->offering)->value('id');
    $this->actingAs($this->admin)->postJson("{$this->base}/sheets/{$id}/defer", ['learner_id' => $this->learners[1], 'reason' => 'Bereavement; the board accepted the letter.'])->assertOk();
    resultsThroughBoard($this, 'main');
    $this->actingAs($this->admin)->postJson("{$this->base}/release", ['period_id' => $this->semester, 'sitting' => 'main'])->assertOk();

    $record = $this->actingAs($this->admin)->getJson("/api/v1/results/learners/{$this->learners[1]}")->assertOk();
    expect($record->json('semesters.0.courses.0'))->toMatchArray(['result' => 'deferred', 'grade' => null])->and($record->json('open'))->toBe(1);

    // At the supplementary sitting a deferred exam is a first attempt: no cap.
    $this->actingAs($this->admin)->postJson("{$this->base}/supplementary", ['period_id' => $this->semester])->assertOk()->assertJsonPath('opened', 1);
    $this->actingAs($this->lecturer)->putJson("{$this->base}/courses/{$this->offering}/marks", ['sitting' => 'supplementary', 'marks' => [['learner_id' => $this->learners[1], 'exam' => 80]]])->assertOk();
    $supp = collect($this->actingAs($this->lecturer)->getJson("{$this->base}/courses/{$this->offering}?sitting=supplementary")->json('lines'))->firstWhere('name', 'Thoko Mwale');
    expect($supp)->toMatchArray(['kind' => 'deferred', 'mark' => 68, 'grade' => 'C+']);
});

it('keeps a saved scale and the settings, and refuses a scale with a gap', function (): void {
    $bands = [['grade' => 'P', 'min' => 50, 'max' => 100, 'points' => 4], ['grade' => 'F', 'min' => 0, 'max' => 48, 'points' => 0]];
    $this->actingAs($this->lecturer)->postJson("{$this->base}/setup/scales", ['period_id' => $this->semester, 'pass_mark' => 50, 'bands' => $bands])->assertForbidden();
    $this->actingAs($this->admin)->postJson("{$this->base}/setup/scales", ['period_id' => $this->semester, 'pass_mark' => 50, 'bands' => $bands])->assertUnprocessable();
    $bands[1]['max'] = 49;
    $this->actingAs($this->admin)->postJson("{$this->base}/setup/scales", ['period_id' => $this->semester, 'label' => 'Pass or fail', 'pass_mark' => 50, 'bands' => $bands])->assertCreated();
    $this->actingAs($this->admin)->putJson("{$this->base}/setup/settings", ['bar_percent' => 75, 'bar_days' => 10, 'hold_slip_for_fees' => true])->assertOk();

    $setup = $this->actingAs($this->admin)->getJson("{$this->base}/setup")->assertOk();
    expect($setup->json('scale.label'))->toBe('Pass or fail')->and($setup->json('settings'))->toBe(['bar_percent' => 75, 'bar_days' => 10, 'hold_slip_for_fees' => true]);
    // At 75% the student who attended 7 of 10 is still barred; the student sees it on Today.
    $student = resultsStudent($this, $this->learners[2], '0991 234 569');
    expect($this->actingAs($student)->getJson('/api/v1/student/results/attendance')->assertOk()->json('courses.0'))->toMatchArray(['rate' => 70, 'state' => 'below']);
});
