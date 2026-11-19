<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Sms\LogSmsProvider;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

const LESSON_DAY = '2026-11-17'; // a Tuesday in Term 1

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    app()->forgetScopedInstances();
    $this->travelTo('2026-11-17 08:20:00'); // 10:20 in Blantyre, during period 3
    LogSmsProvider::$sent = [];
    $this->school = TwoCampusSchool::make();

    foreach (['primary', 'secondary'] as $division) {
        DB::table('academic_periods')->insert(['id' => Ids::next(), 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id($division),
            'name' => 'Term 1', 'position' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);
    }

    $system = Role::query()->where('is_system', true)->value('id');
    $this->people = [];

    foreach (['admin' => 'Grace Phiri', 'tadala' => 'Tadala Mvula', 'joseph' => 'Joseph Nyirenda', 'mercy' => 'Mercy Banda'] as $key => $name) {
        $this->people[$key] = User::factory()->create(['name' => $name]);
        RoleAssignment::query()->create(['user_id' => $this->people[$key]->id, 'role_id' => $system, 'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
        $this->inPlace('division', $this->school->id('secondary'), $this->people[$key]);
    }

    $this->class = $this->school->id('class_secondary_b');
    $this->west = Ids::next();
    DB::table('academic_classes')->insert(['id' => $this->west, 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id('secondary'),
        'campus_id' => $this->school->id('campus_b'), 'level_code' => 'F1', 'stream' => 'West', 'name' => 'Form 1 West', 'position' => 2, 'created_at' => now(), 'updated_at' => now()]);
    $this->subjects = [];

    foreach (['BIO' => 'Biology', 'MAT' => 'Mathematics'] as $short => $name) {
        $this->subjects[$short] = Ids::next();
        DB::table('academic_subjects')->insert(['id' => $this->subjects[$short], 'division_id' => $this->school->id('secondary'), 'short' => $short, 'name' => $name,
            'core' => true, 'levels' => json_encode(['F1']), 'position' => count($this->subjects), 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->learners = [];

    foreach ([['Chimwemwe', 'Chirwa'], ['Dalitso', 'Gondwe'], ['Fatsani', 'Kumwenda']] as [$first, $surname]) {
        $this->learners[] = $this->actingAs($this->people['admin'])->postJson('/api/v1/learners', [
            'first_name' => $first, 'surname' => $surname, 'sex' => 'female', 'date_of_birth' => '2012-05-02', 'class_id' => $this->class, 'admitted_on' => '2026-09-08',
            'contacts' => [['name' => 'Ruth '.$surname, 'relationship' => 'mother', 'phone' => '0991 234 567', 'main' => true], ['name' => 'Peter '.$surname, 'relationship' => 'father', 'phone' => '0888 410 220']],
        ])->assertCreated()->json('id');
    }
});

function lessonBells(): array
{
    return ['division_id' => test()->school->id('secondary'), 'campus_id' => test()->school->id('campus_b'), 'plans' => [[
        'name' => 'Monday to Friday', 'weekdays' => [1, 2, 3, 4, 5], 'periods' => [
            ['name' => 'Period 1', 'starts' => '07:30', 'ends' => '08:15', 'kind' => 'lesson'],
            ['name' => 'Period 2', 'starts' => '08:15', 'ends' => '09:00', 'kind' => 'lesson'],
            ['name' => 'Break', 'starts' => '09:00', 'ends' => '10:15', 'kind' => 'break'],
            ['name' => 'Period 3', 'starts' => '10:15', 'ends' => '11:00', 'kind' => 'lesson'],
            ['name' => 'Period 4', 'starts' => '11:00', 'ends' => '11:45', 'kind' => 'lesson'],
        ]]]];
}

function lessonSet(string $class, int $period, string $subject, ?string $teacher): void
{
    test()->actingAs(test()->people['admin'])->putJson('/api/v1/academics/timetable/period', ['class_id' => $class, 'weekday' => 2, 'period' => $period, 'subject_id' => $subject, 'user_id' => $teacher])->assertOk();
}

function lessonMarks(array $learners, array $statuses): array
{
    return array_map(fn (string $id, string $status): array => ['learner_id' => $id, 'status' => $status, 'note' => null], $learners, $statuses);
}

function slotOf(int $period, ?string $class = null): string
{
    return (string) DB::table('academic_timetable')->where('class_id', $class ?? test()->class)->where('weekday', 2)->where('period', $period)->whereNull('ends_on')->value('id');
}

it('sets bell times and a class timetable, and refuses a teacher in two classes at once', function (): void {
    $this->actingAs($this->people['admin'])->putJson('/api/v1/academics/timetable/bells', [...lessonBells(), 'plans' => [['name' => 'Odd', 'weekdays' => [1], 'periods' => [
        ['name' => 'Period 1', 'starts' => '08:00', 'ends' => '07:00', 'kind' => 'lesson']]]]])->assertUnprocessable()->assertJsonValidationErrors(['plans.0.periods.0']);
    $this->actingAs($this->people['admin'])->putJson('/api/v1/academics/timetable/bells', lessonBells())->assertOk()
        ->assertJsonPath('days.1', ['weekday' => 2, 'lessons' => 4, 'plan' => 'Monday to Friday']);

    lessonSet($this->class, 3, $this->subjects['BIO'], $this->people['tadala']->id);

    $this->actingAs($this->people['admin'])->putJson('/api/v1/academics/timetable/period', ['class_id' => $this->west, 'weekday' => 2, 'period' => 3,
        'subject_id' => $this->subjects['MAT'], 'user_id' => $this->people['tadala']->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['user_id' => 'Tadala Mvula teaches F1 at this time.']);
    $this->actingAs($this->people['admin'])->putJson('/api/v1/academics/timetable/period', ['class_id' => $this->class, 'weekday' => 2, 'period' => 9,
        'subject_id' => $this->subjects['MAT']])->assertUnprocessable()->assertJsonValidationErrors(['period']);

    $week = $this->actingAs($this->people['admin'])->getJson('/api/v1/academics/timetable?division_id='.$this->school->id('secondary').'&campus_id='.$this->school->id('campus_b').'&class_id='.$this->class)->assertOk();
    expect($week->json('grid'))->toBe([['weekday' => 2, 'period' => 3, 'subject_id' => $this->subjects['BIO'], 'activity' => null, 'user_id' => $this->people['tadala']->id]])
        ->and($week->json('free'))->toBe(19);

    // A change applies from today; the lesson keeps its name, a new row is in force.
    lessonSet($this->class, 3, $this->subjects['MAT'], $this->people['joseph']->id);
    expect(DB::table('academic_timetable')->where('class_id', $this->class)->count())->toBe(2)
        ->and(DB::table('academic_timetable')->where('class_id', $this->class)->whereNull('ends_on')->value('subject_id'))->toBe($this->subjects['MAT']);
});

it('takes a lesson register, records cover, and lists a learner who missed a lesson', function (): void {
    $this->actingAs($this->people['admin'])->putJson('/api/v1/academics/timetable/bells', lessonBells())->assertOk();
    lessonSet($this->class, 1, $this->subjects['MAT'], $this->people['joseph']->id);
    lessonSet($this->class, 3, $this->subjects['BIO'], $this->people['tadala']->id);

    $this->actingAs($this->people['mercy'])->postJson("/api/v1/registers/class/{$this->class}", ['day' => LESSON_DAY, 'marks' => lessonMarks($this->learners, ['present', 'present', 'absent'])])->assertOk();

    // Period 4 has not begun: its register cannot be taken yet.
    lessonSet($this->class, 4, $this->subjects['MAT'], $this->people['joseph']->id);
    $this->actingAs($this->people['joseph'])->postJson('/api/v1/registers/lesson/'.slotOf(4), ['day' => LESSON_DAY, 'marks' => lessonMarks($this->learners, ['present', 'present', 'absent'])])
        ->assertUnprocessable()->assertJsonValidationErrors(['day']);

    $lesson = $this->actingAs($this->people['tadala'])->getJson('/api/v1/registers/lesson/'.slotOf(3).'?day='.LESSON_DAY)->assertOk()
        ->assertJsonPath('lesson.subject', 'Biology')->assertJsonPath('can_take', true)->assertJsonPath('cover', false);
    expect(array_column($lesson->json('learners'), 'morning'))->toBe(['present', 'present', 'absent']);

    // Joseph takes Tadala's lesson: it is cover. Chimwemwe was here this morning and is absent now.
    $this->actingAs($this->people['joseph'])->postJson('/api/v1/registers/lesson/'.slotOf(3), ['day' => LESSON_DAY, 'marks' => lessonMarks($this->learners, ['absent', 'present', 'absent'])])
        ->assertOk()->assertJsonPath('register.cover', true)->assertJsonPath('register.submitted_by', 'Joseph Nyirenda');

    $this->actingAs($this->people['joseph'])->postJson('/api/v1/registers/lesson/'.slotOf(3), ['day' => LESSON_DAY, 'marks' => lessonMarks($this->learners, ['present', 'present', 'absent'])])
        ->assertUnprocessable()->assertJsonValidationErrors(['register']);

    $missed = $this->actingAs($this->people['admin'])->getJson('/api/v1/registers/missed?day='.LESSON_DAY)->assertOk()
        ->assertJsonPath('notice_on', false)->assertJsonPath('totals.missed', 1);
    expect($missed->json('missed.0'))->toMatchArray(['learner' => 'Chimwemwe Chirwa', 'morning' => 'present', 'subject' => 'Biology', 'starts' => '10:15', 'cover' => true, 'told' => []])
        ->and(LogSmsProvider::$sent)->toHaveCount(1); // the morning absence only; the missed-lesson notice starts off

    $today = $this->actingAs($this->people['admin'])->getJson('/api/v1/registers/lessons?day='.LESSON_DAY)->assertOk();
    // Period 1 needs no register (only the first period after a break does), so it is not missing one.
    expect(array_column($today->json('places.0.classes.0.lessons'), 'state'))->toBe(['not_needed', 'cover', 'later'])
        ->and(array_column($today->json('places.0.classes.0.lessons'), 'required'))->toBe([false, true, false])
        ->and($today->json('totals'))->toMatchArray(['lessons' => 3, 'cover' => 1, 'no_register' => 0]);

    // Switched on, the next missed lesson tells the main contact.
    DB::table('institution_topic_settings')->insert(['topic_key' => 'attendance.lesson_missed', 'enabled' => true, 'updated_at' => now()]);
    $this->travelTo('2026-11-17 09:05:00');
    $this->actingAs($this->people['joseph'])->postJson('/api/v1/registers/lesson/'.slotOf(4), ['day' => LESSON_DAY, 'marks' => lessonMarks($this->learners, ['present', 'absent', 'absent'])])->assertOk();
    expect(LogSmsProvider::$sent)->toHaveCount(2)
        ->and(LogSmsProvider::$sent[1]['text'])->toContain('Dalitso Gondwe was at school this morning but was absent from Mathematics at 11:00');
});

it('shows a teacher their day, and lets the office cover the lessons of a teacher who is away', function (): void {
    $this->actingAs($this->people['admin'])->putJson('/api/v1/academics/timetable/bells', lessonBells())->assertOk();
    lessonSet($this->class, 3, $this->subjects['BIO'], $this->people['tadala']->id);
    lessonSet($this->class, 4, $this->subjects['MAT'], $this->people['tadala']->id);
    lessonSet($this->west, 4, $this->subjects['MAT'], $this->people['joseph']->id);

    $this->actingAs($this->people['tadala'])->getJson('/api/v1/registers/my-day?day='.LESSON_DAY)->assertOk()
        ->assertJsonPath('away', null)->assertJsonPath('lessons.0.state', 'now')->assertJsonPath('lessons.1.state', 'later')->assertJsonPath('lessons.0.mine', true);

    $this->actingAs($this->people['tadala'])->postJson('/api/v1/registers/away', ['day' => LESSON_DAY, 'away' => true, 'reason' => 'Unwell.'])->assertOk();
    $today = $this->actingAs($this->people['admin'])->getJson('/api/v1/registers/lessons?day='.LESSON_DAY)->assertOk()
        ->assertJsonPath('away.0.name', 'Tadala Mvula')->assertJsonPath('away.0.reason', 'Unwell.')->assertJsonPath('totals.needs_cover', 2);

    // Joseph teaches at period 4, so only Mercy (and the office) are free then.
    $free = $this->actingAs($this->people['admin'])->getJson('/api/v1/registers/cover/free?slot_id='.slotOf(4).'&day='.LESSON_DAY)->assertOk()->json('free');
    expect(array_column($free, 'name'))->toBe(['Grace Phiri', 'Mercy Banda']);

    $this->actingAs($this->people['admin'])->postJson('/api/v1/registers/cover', ['slot_id' => slotOf(4), 'day' => LESSON_DAY, 'user_id' => $this->people['joseph']->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
    $this->actingAs($this->people['admin'])->postJson('/api/v1/registers/cover', ['slot_id' => slotOf(4), 'day' => LESSON_DAY, 'user_id' => $this->people['mercy']->id])
        ->assertOk()->assertJsonPath('totals.needs_cover', 1)->assertJsonPath('away.0.lessons.1.asked', 'Mercy Banda');

    $this->actingAs($this->people['mercy'])->getJson('/api/v1/registers/my-day?day='.LESSON_DAY)->assertOk()
        ->assertJsonPath('lessons.0.subject', 'Mathematics')->assertJsonPath('lessons.0.mine', false)
        ->assertJsonPath('lessons.0.cover_for', 'Tadala Mvula')->assertJsonPath('lessons.0.asked_by', 'Grace Phiri');
    expect($today->json('can_arrange'))->toBeTrue();
});

it('takes lesson registers only where the level\'s profile says so', function (): void {
    // Inside Secondary, only Secondary's level shows (D-099).
    expect($this->actingAs($this->people['admin'])->getJson('/api/v1/registers/levels')->assertOk()->json('levels'))
        ->toBe([['division' => 'Secondary', 'mode' => 'daily_and_lessons']]);
    $this->inPlace('division', $this->school->id('primary'), $this->people['admin']);
    $this->inPlace('division', $this->school->id('primary'), $this->people['mercy']);
    expect($this->actingAs($this->people['admin'])->getJson('/api/v1/registers/levels')->json('levels'))->toBe([['division' => 'Primary', 'mode' => 'daily']]);

    $primary = Ids::next();
    DB::table('academic_subjects')->insert(['id' => $primary, 'division_id' => $this->school->id('primary'), 'short' => 'ENG', 'name' => 'English',
        'core' => true, 'levels' => json_encode(['STD1']), 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->people['admin'])->putJson('/api/v1/academics/timetable/bells', [...lessonBells(), 'division_id' => $this->school->id('primary'), 'campus_id' => $this->school->id('campus_a')])->assertOk();
    lessonSet($this->school->id('class_primary_a'), 3, $primary, $this->people['mercy']->id);

    $this->actingAs($this->people['mercy'])->postJson('/api/v1/registers/lesson/'.slotOf(3, $this->school->id('class_primary_a')), ['day' => LESSON_DAY, 'marks' => []])
        ->assertUnprocessable()->assertJsonValidationErrors(['slot_id' => 'This level takes a morning register only.']);
    $this->inPlace('division', $this->school->id('secondary'), $this->people['admin']);
    $this->actingAs($this->people['admin'])->getJson('/api/v1/registers?day='.LESSON_DAY)->assertOk()->assertJsonPath('lessons', true);
});

it('opens a lesson register as the last register left the class, so nothing changed is one confirm', function (): void {
    $this->actingAs($this->people['admin'])->putJson('/api/v1/academics/timetable/bells', lessonBells())->assertOk();
    lessonSet($this->class, 1, $this->subjects['MAT'], $this->people['joseph']->id);
    lessonSet($this->class, 3, $this->subjects['BIO'], $this->people['tadala']->id);
    $this->actingAs($this->people['mercy'])->postJson("/api/v1/registers/class/{$this->class}", ['day' => LESSON_DAY, 'marks' => lessonMarks($this->learners, ['present', 'late', 'absent'])])->assertOk();

    $this->actingAs($this->people['tadala'])->getJson('/api/v1/registers/lesson/'.slotOf(1).'?day='.LESSON_DAY)->assertOk()
        ->assertJsonPath('previous.kind', 'morning')->assertJsonPath('previous.by', 'Mercy Banda')->assertJsonPath('lesson.required', false);
    $this->actingAs($this->people['joseph'])->postJson('/api/v1/registers/lesson/'.slotOf(1), ['day' => LESSON_DAY, 'marks' => lessonMarks($this->learners, ['present', 'present', 'absent'])])->assertOk();

    $lesson = $this->actingAs($this->people['tadala'])->getJson('/api/v1/registers/lesson/'.slotOf(3).'?day='.LESSON_DAY)->assertOk()
        ->assertJsonPath('previous.kind', 'lesson')->assertJsonPath('previous.name', 'Period 1')->assertJsonPath('previous.by', 'Joseph Nyirenda')
        ->assertJsonPath('lesson.required', true);
    expect(array_column($lesson->json('learners'), 'before'))->toBe(['present', 'present', 'absent']);
});

it('keeps activities on the timetable: for the whole level in the bell times, and for one class in its week', function (): void {
    $bells = lessonBells();
    $bells['plans'][0]['periods'][] = ['name' => 'Sports and clubs', 'starts' => '13:30', 'ends' => '15:00', 'kind' => 'activity', 'register' => true];
    $bells['plans'][0]['periods'][0]['register'] = true;
    $saved = $this->actingAs($this->people['admin'])->putJson('/api/v1/academics/timetable/bells', $bells)->assertOk();
    expect(array_column($saved->json('plans.0.periods'), 'register'))->toBe([true, false, false, true, false, true]);

    $this->actingAs($this->people['admin'])->putJson('/api/v1/academics/timetable/period', ['class_id' => $this->class, 'weekday' => 2, 'period' => 4,
        'subject_id' => $this->subjects['MAT'], 'activity' => 'Games'])->assertUnprocessable()->assertJsonValidationErrors(['activity']);
    $week = $this->actingAs($this->people['admin'])->putJson('/api/v1/academics/timetable/period', ['class_id' => $this->class, 'weekday' => 2, 'period' => 4,
        'activity' => 'Games', 'user_id' => $this->people['joseph']->id])->assertOk();
    expect($week->json('grid.0'))->toMatchArray(['period' => 4, 'subject_id' => null, 'activity' => 'Games'])->and($week->json('counts'))->toBe([]);

    $this->travelTo('2026-11-17 11:40:00'); // 13:40, during Sports and clubs
    $today = $this->actingAs($this->people['admin'])->getJson('/api/v1/registers/lessons?day='.LESSON_DAY)->assertOk();
    $f1 = collect($today->json('places.0.classes'))->firstWhere('id', $this->class)['lessons'];
    expect(array_column($f1, 'subject'))->toBe(['Games', 'Sports and clubs'])
        ->and(array_column($f1, 'kind'))->toBe(['activity', 'activity'])
        ->and(array_column($f1, 'whole_level'))->toBe([false, true])
        ->and(array_column($f1, 'state'))->toBe(['not_needed', 'now']);

    // The whole level's activity can be taken like a lesson, and keeps its name.
    $slot = $f1[1]['slot_id'];
    $this->actingAs($this->people['mercy'])->postJson('/api/v1/registers/lesson/'.$slot, ['day' => LESSON_DAY, 'marks' => lessonMarks($this->learners, ['present', 'present', 'present'])])
        ->assertOk()->assertJsonPath('lesson.subject', 'Sports and clubs')->assertJsonPath('lesson.kind', 'activity')->assertJsonPath('register.cover', true);
    expect(DB::table('attendance_registers')->where('slot_id', $slot)->value('activity'))->toBe('Sports and clubs');
});
