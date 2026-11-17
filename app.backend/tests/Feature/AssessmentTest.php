<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Application\TermGrades;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'assessment', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    app()->forgetScopedInstances();
    $this->travelTo('2026-11-17 08:20:00');
    $this->school = TwoCampusSchool::make();
    $this->artisan('acadly:sync')->assertSuccessful(); // gives the new levels their scales
    $this->terms = [];

    foreach (['primary', 'secondary'] as $division) {
        $this->terms[$division] = Ids::next();
        DB::table('academic_periods')->insert(['id' => $this->terms[$division], 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id($division),
            'name' => 'Term 1', 'position' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->people = [];

    foreach (['admin' => 'Grace Phiri', 'tadala' => 'Tadala Mvula', 'mercy' => 'Mercy Banda', 'stranger' => 'Peter Zulu'] as $key => $name) {
        $this->people[$key] = User::factory()->create(['name' => $name]);
    }

    RoleAssignment::query()->create(['user_id' => $this->people['admin']->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $teacher = Role::query()->create(['key' => 'subject_teacher', 'name' => 'Subject teacher']);
    $teacher->grant([AssessmentPermission::Create, AssessmentPermission::Enter]);
    RoleAssignment::query()->create(['user_id' => $this->people['tadala']->id, 'role_id' => $teacher->id, 'scope_type' => ScopeType::Campus,
        'scope_id' => $this->school->id('campus_b'), 'starts_at' => now()->subMinute()]);
    $office = Role::query()->create(['key' => 'academic_officer', 'name' => 'Academic officer']);
    $office->grant([AssessmentPermission::Lock, AssessmentPermission::View]);
    RoleAssignment::query()->create(['user_id' => $this->people['mercy']->id, 'role_id' => $office->id, 'scope_type' => ScopeType::Institution,
        'scope_id' => null, 'starts_at' => now()->subMinute()]);

    $this->class = $this->school->id('class_secondary_b');
    $this->subjects = [];

    foreach (['BIO' => 'Biology', 'MAT' => 'Mathematics'] as $short => $name) {
        $this->subjects[$short] = Ids::next();
        DB::table('academic_subjects')->insert(['id' => $this->subjects[$short], 'division_id' => $this->school->id('secondary'), 'short' => $short, 'name' => $name,
            'core' => true, 'levels' => json_encode(['F1']), 'position' => count($this->subjects), 'created_at' => now(), 'updated_at' => now()]);
    }

    DB::table('academic_teaching')->insert(['id' => Ids::next(), 'class_id' => $this->class, 'subject_id' => $this->subjects['BIO'], 'user_id' => $this->people['tadala']->id,
        'given_by' => $this->people['admin']->id, 'given_at' => now()->subMonth()]);

    $this->learners = [];

    foreach ([['Alinafe', 'Banda'], ['Chimwemwe', 'Chirwa'], ['Dalitso', 'Gondwe']] as [$first, $surname]) {
        $this->learners[] = $this->actingAs($this->people['admin'])->postJson('/api/v1/learners', [
            'first_name' => $first, 'surname' => $surname, 'sex' => 'female', 'date_of_birth' => '2012-05-02', 'class_id' => $this->class, 'admitted_on' => '2026-09-08',
            'contacts' => [['name' => 'Ruth '.$surname, 'relationship' => 'mother', 'phone' => '0991 234 567', 'main' => true], ['name' => 'Peter '.$surname, 'relationship' => 'father', 'phone' => '0888 410 220']],
        ])->assertCreated()->json('id');
    }
});

/** Sets an assessment and gives back its id. */
function assessmentSet(User $by, string $kind, string $title, int $outOf, string $subject = 'BIO', bool $counts = true, string $heldOn = '2026-11-10'): string
{
    return test()->actingAs($by)->postJson('/api/v1/assessment/assessments', ['class_id' => test()->class, 'subject_id' => test()->subjects[$subject],
        'kind' => $kind, 'title' => $title, 'held_on' => $heldOn, 'out_of' => $outOf, 'counts' => $counts])->assertCreated()->json('assessment.id');
}

/**
 * Marks for the three learners, in order: a number, or a reason.
 *
 * @param  list<int|float|string|null>  $given
 */
function assessmentMarks(array $given): array
{
    return array_map(fn (string $id, int|float|string|null $m): array => is_string($m) ? ['learner_id' => $id, 'value' => null, 'reason' => $m] : ['learner_id' => $id, 'value' => $m, 'reason' => null],
        test()->learners, $given);
}

/** Enters the marks, submits them as the teacher, and locks them as the office. */
function assessmentLocked(User $teacher, string $id, array $given): void
{
    test()->actingAs($teacher)->putJson("/api/v1/assessment/assessments/{$id}/marks", ['marks' => assessmentMarks($given)])->assertOk();
    test()->actingAs($teacher)->postJson("/api/v1/assessment/assessments/{$id}/submit")->assertOk()->assertJsonPath('assessment.state', 'submitted');
    test()->actingAs(test()->people['mercy'])->postJson("/api/v1/assessment/assessments/{$id}/lock")->assertOk()->assertJsonPath('assessment.state', 'locked');
}

function scaleId(string $key): string
{
    return (string) DB::table('assessment_scales')->where('seed_key', $key)->value('id');
}

it('seeds the scales once, gives each level its scale, and checks the bands of a change', function (): void {
    $scales = $this->actingAs($this->people['admin'])->getJson('/api/v1/assessment/scales')->assertOk();
    expect(array_column($scales->json('scales'), 'name'))->toBe(['Primary 1 to 4', 'PSLCE grades', 'Percent and letters', 'JCE grades', 'MSCE points', 'British primary', 'GCSE 9 to 1', 'A level'])
        ->and(collect($scales->json('levels'))->firstWhere('level_code', 'F1')['scale_id'])->toBe(scaleId('jce'))
        ->and(collect($scales->json('levels'))->firstWhere('level_code', 'STD1')['scale_id'])->toBe(scaleId('primary_1_4'))
        ->and(collect($scales->json('levels'))->firstWhere('level_code', 'STD8')['scale_id'])->toBe(scaleId('pslce'))
        ->and(collect($scales->json('scales'))->firstWhere('name', 'MSCE points'))->toMatchArray(['overall' => 'best_six', 'pass_from' => '8']);

    $msce = collect($scales->json('scales'))->firstWhere('name', 'MSCE points');
    $gap = [...$msce, 'bands' => array_values(array_filter($msce['bands'], fn (array $b): bool => $b['grade'] !== '5'))];
    $this->actingAs($this->people['admin'])->putJson('/api/v1/assessment/scales/'.$msce['id'], $gap)
        ->assertUnprocessable()->assertJsonValidationErrors(['bands' => 'The bands must cover 0 to 100%, with no gap and no overlap.']);
    $this->actingAs($this->people['admin'])->putJson('/api/v1/assessment/scales/'.$msce['id'], [...$msce, 'pass_from' => 'Z'])
        ->assertUnprocessable()->assertJsonValidationErrors(['pass_from']);
    $this->actingAs($this->people['admin'])->putJson('/api/v1/assessment/scales/'.scaleId('jce'), [...collect($scales->json('scales'))->firstWhere('name', 'JCE grades'), 'overall' => 'average_grade'])
        ->assertUnprocessable()->assertJsonValidationErrors(['overall' => 'An average grade or a best six needs every grade to be a number.']);

    $bands = $msce['bands'];
    $bands[1]['word'] = 'Upper distinction';
    $this->actingAs($this->people['admin'])->putJson('/api/v1/assessment/scales/'.$msce['id'], [...$msce, 'bands' => $bands])->assertOk();
    $this->actingAs($this->people['admin'])->putJson('/api/v1/assessment/scales', ['name' => 'Pass or fail', 'bands' => [
        ['grade' => 'P', 'word' => 'Pass', 'from' => 50, 'to' => 100], ['grade' => 'F', 'word' => 'Fail', 'from' => 0, 'to' => 49]]])->assertOk();
    $this->actingAs($this->people['admin'])->putJson('/api/v1/assessment/scales/'.scaleId('jce'), [...collect($scales->json('scales'))->firstWhere('name', 'JCE grades'), 'retired' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['retired']);

    // The sync adds nothing more, and keeps what the school changed.
    $this->artisan('acadly:sync')->assertSuccessful();
    expect(DB::table('assessment_scales')->count())->toBe(9)
        ->and(collect(json_decode((string) DB::table('assessment_scales')->where('id', $msce['id'])->value('bands'), true))->firstWhere('grade', '2')['word'])->toBe('Upper distinction');

    $this->actingAs($this->people['tadala'])->getJson('/api/v1/assessment/scales')->assertForbidden();
});

it('saves a grading plan as a new version: weights add up to 100, and a reason once the term has begun', function (): void {
    $plan = fn (array $parts, string $reason = '', ?string $period = null): array => ['division_id' => $this->school->id('secondary'), 'level_code' => 'F1',
        'period_id' => $period ?? $this->terms['secondary'], 'mode' => 'continuous', 'parts' => $parts, 'reason' => $reason];
    $ca = fn (int $w): array => ['key' => 'continuous', 'label' => 'Continuous assessment', 'weight' => $w, 'kinds' => ['class_test', 'weekly_test', 'mid_term'], 'rule' => 'average', 'missing' => 'zero'];
    $exam = fn (int $w): array => ['key' => 'exam', 'label' => 'End-of-term exam', 'weight' => $w, 'kinds' => ['end_of_term'], 'rule' => 'single', 'missing' => 'ask'];

    $this->actingAs($this->people['admin'])->postJson('/api/v1/assessment/plans', $plan([$ca(30), $exam(60)], 'Board decision.'))
        ->assertUnprocessable()->assertJsonValidationErrors(['parts' => 'The weights must add up to 100%.']);
    $this->actingAs($this->people['admin'])->postJson('/api/v1/assessment/plans', $plan([$ca(40), [...$exam(60), 'kinds' => ['end_of_term', 'mid_term']]], 'Board decision.'))
        ->assertUnprocessable()->assertJsonValidationErrors(['parts.1.kinds']);
    $this->actingAs($this->people['admin'])->postJson('/api/v1/assessment/plans', $plan([$ca(30), $exam(70)]))
        ->assertUnprocessable()->assertJsonValidationErrors(['reason' => 'The term has begun, so a new version needs a reason.']);

    $this->actingAs($this->people['admin'])->postJson('/api/v1/assessment/plans', $plan([$ca(30), $exam(70)], 'Agreed by the heads of department.'))->assertCreated()
        ->assertJsonPath('plan.version', 1)->assertJsonPath('plan.summary', 'Continuous assessment 30 · End-of-term exam 70');
    $this->actingAs($this->people['admin'])->postJson('/api/v1/assessment/plans', $plan([$ca(40), $exam(60)], 'Back to the board weights.'))->assertCreated();

    $read = $this->actingAs($this->people['admin'])->getJson('/api/v1/assessment/plans?division_id='.$this->school->id('secondary').'&level_code=F1&period_id='.$this->terms['secondary'])->assertOk();
    expect($read->json('plan.version'))->toBe(2)->and($read->json('begun'))->toBeTrue()
        ->and(array_column($read->json('versions'), 'version'))->toBe([2, 1])
        ->and($read->json('versions.0.created_by'))->toBe('Grace Phiri');
    expect(fn () => DB::transaction(fn () => DB::table('grading_plans')->update(['reason' => 'Changed.'])))->toThrow(Exception::class);

    // Before a term begins, a plan needs no reason.
    $next = Ids::next();
    DB::table('academic_periods')->insert(['id' => $next, 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id('secondary'),
        'name' => 'Term 2', 'position' => 2, 'starts_on' => '2027-01-04', 'ends_on' => '2027-04-02', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->people['admin'])->postJson('/api/v1/assessment/plans', $plan([$ca(50), $exam(50)], '', $next))->assertCreated()->assertJsonPath('begun', false);

    $this->actingAs($this->people['tadala'])->postJson('/api/v1/assessment/plans', $plan([$ca(40), $exam(60)], 'Mine.'))->assertForbidden();
});

it('lets a teacher set an assessment only for a class and subject they teach', function (): void {
    $options = $this->actingAs($this->people['tadala'])->getJson('/api/v1/assessment/options')->assertOk();
    expect(array_column($options->json('choices'), 'subject'))->toBe(['Biology'])
        ->and(collect($options->json('choices.0.lands'))->firstWhere('kind', 'class_test'))->toMatchArray(['counts' => true, 'part_label' => 'Continuous assessment', 'weight' => 40, 'others' => 0]);

    $this->actingAs($this->people['tadala'])->postJson('/api/v1/assessment/assessments', ['class_id' => $this->class, 'subject_id' => $this->subjects['MAT'],
        'kind' => 'class_test', 'title' => 'Fractions', 'held_on' => '2026-11-17', 'out_of' => 20])
        ->assertUnprocessable()->assertJsonValidationErrors(['subject_id' => 'You may set assessments only for the subjects you teach in this class.']);
    $this->actingAs($this->people['tadala'])->postJson('/api/v1/assessment/assessments', ['class_id' => $this->class, 'subject_id' => $this->subjects['BIO'],
        'kind' => 'class_test', 'title' => '', 'held_on' => '2027-02-01', 'out_of' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors(['title', 'held_on', 'out_of']);

    $sheet = $this->actingAs($this->people['tadala'])->postJson('/api/v1/assessment/assessments', ['class_id' => $this->class, 'subject_id' => $this->subjects['BIO'],
        'kind' => 'class_test', 'title' => 'Cells', 'held_on' => '2026-11-17', 'out_of' => 20, 'counts' => true])->assertCreated();
    expect($sheet->json('assessment'))->toMatchArray(['state' => 'marking', 'subject' => 'Biology', 'part' => ['key' => 'continuous', 'label' => 'Continuous assessment', 'weight' => 40]])
        ->and(array_column($sheet->json('learners'), 'name'))->toBe(['Alinafe Banda', 'Chimwemwe Chirwa', 'Dalitso Gondwe'])
        ->and($sheet->json('can_enter'))->toBeTrue()
        ->and(DB::table('audit_events')->where('action', 'assessment.set')->count())->toBe(1);

    // Someone who keeps the grading plans may set one for any subject of the class.
    assessmentSet($this->people['admin'], 'class_test', 'Fractions', 20, 'MAT');

    $mine = $this->actingAs($this->people['tadala'])->getJson('/api/v1/assessment/mine')->assertOk();
    expect(array_column($mine->json('assessments'), 'title'))->toBe(['Cells'])->and($mine->json('totals.to_finish'))->toBe(1);

    $this->actingAs($this->people['stranger'])->postJson('/api/v1/assessment/assessments', ['class_id' => $this->class, 'subject_id' => $this->subjects['BIO'],
        'kind' => 'class_test', 'title' => 'Cells', 'held_on' => '2026-11-17', 'out_of' => 20])->assertForbidden();
});

it('saves only the marks that changed, and refuses to submit while a learner has neither a mark nor a reason', function (): void {
    $id = assessmentSet($this->people['tadala'], 'weekly_test', 'Weekly test 4', 20);
    $url = "/api/v1/assessment/assessments/{$id}";

    $this->actingAs($this->people['tadala'])->putJson("{$url}/marks", ['marks' => array_slice(assessmentMarks([17, 12, null]), 0, 2)])->assertOk()->assertJsonPath('saved', 2)
        ->assertJsonPath('sheet.stats.marked', 2)->assertJsonPath('sheet.learners.0.grade', 'A');
    $this->actingAs($this->people['tadala'])->putJson("{$url}/marks", ['marks' => array_slice(assessmentMarks([17, 12, null]), 0, 2)])->assertOk()->assertJsonPath('saved', 0);
    $this->actingAs($this->people['tadala'])->putJson("{$url}/marks", ['marks' => array_slice(assessmentMarks([17, 13.5, null]), 0, 2)])->assertOk()->assertJsonPath('saved', 1);
    $this->actingAs($this->people['tadala'])->putJson("{$url}/marks", ['marks' => assessmentMarks([21, null, 'sick'])])
        ->assertUnprocessable()->assertJsonValidationErrors(['marks.0' => 'A mark is a number from 0 to 20, with up to two decimal places.', 'marks.2']);
    expect(DB::table('assessment_marks')->where('assessment_id', $id)->count())->toBe(3);

    $this->actingAs($this->people['tadala'])->postJson("{$url}/submit")->assertUnprocessable()
        ->assertJsonPath('code', 'step_not_admissible')->assertJsonPath('reason', '1 learner has neither a mark nor a reason.');
    $this->actingAs($this->people['tadala'])->getJson($url)->assertOk()->assertJsonPath('can_submit', false)
        ->assertJsonPath('submit_blocked.reason', '1 learner has neither a mark nor a reason.');

    $this->actingAs($this->people['tadala'])->putJson("{$url}/marks", ['marks' => [assessmentMarks([null, null, 'absent'])[2]]])->assertOk()->assertJsonPath('saved', 1);
    $this->actingAs($this->people['tadala'])->postJson("{$url}/submit")->assertOk()->assertJsonPath('assessment.state', 'submitted')
        ->assertJsonPath('ladder.2', ['key' => 'submitted', 'label' => 'Submitted', 'done' => false, 'current' => true, 'by' => 'Tadala Mvula', 'at' => DB::table('journey_step_completions')->where('subject_id', $id)->value('occurred_at')]);
    $this->actingAs($this->people['tadala'])->putJson("{$url}/marks", ['marks' => assessmentMarks([18, null, null])])->assertUnprocessable()->assertJsonValidationErrors(['marks']);
    expect(DB::table('notification_requests')->where('topic_key', 'assessment.submitted')->where('subject_id', $id)->count())->toBe(1);
});

it('keeps optional marked papers, a page at a time, by the teacher, and shows them on the record once locked', function (): void {
    Storage::fake('documents');
    $page = fn (): UploadedFile => UploadedFile::fake()->createWithContent('page.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    $pdf = UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n% marked\n%%EOF\n");
    $id = assessmentSet($this->people['tadala'], 'end_of_term', 'End-of-term exam', 100);
    $url = "/api/v1/assessment/assessments/{$id}/papers";

    $this->actingAs($this->people['tadala'])->post($url, ['learner_id' => $this->learners[0], 'file' => $page()], ['Accept' => 'application/json'])->assertOk();
    $sheet = $this->actingAs($this->people['tadala'])->post($url, ['learner_id' => $this->learners[0], 'file' => $pdf], ['Accept' => 'application/json'])->assertOk();
    expect($sheet->json('can_add_papers'))->toBeTrue()
        ->and(array_column($sheet->json('learners.0.paper'), 'type'))->toBe(['image/png', 'application/pdf'])
        ->and($sheet->json('learners.1.paper'))->toBe([]);

    // Only the teacher of the assessment adds papers; a text file is refused.
    $this->actingAs($this->people['mercy'])->post($url, ['learner_id' => $this->learners[1], 'file' => $page()], ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($this->people['tadala'])->post($url, ['learner_id' => $this->learners[1], 'file' => UploadedFile::fake()->createWithContent('notes.txt', 'hello')], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    // Move the PDF first, then remove the photo: the PDF is page 1 and the photo stays recorded.
    [$first, $second] = $sheet->json('learners.0.paper');
    $this->actingAs($this->people['tadala'])->postJson("/api/v1/assessment/papers/{$second['id']}/move", ['earlier' => true])->assertOk()
        ->assertJsonPath('learners.0.paper.0.id', $second['id']);
    $this->actingAs($this->people['tadala'])->deleteJson("/api/v1/assessment/papers/{$first['id']}")->assertOk()
        ->assertJsonPath('learners.0.paper', [['id' => $second['id'], 'document_id' => $second['document_id'], 'position' => 1, 'type' => 'application/pdf']]);
    expect(DB::table('assessment_papers')->whereNotNull('removed_at')->count())->toBe(1);

    // The office, who locks, may open the paper; someone else may not.
    $this->actingAs($this->people['mercy'])->get("/api/v1/documents/{$second['document_id']}/content")->assertOk();
    $this->actingAs($this->people['stranger'])->get("/api/v1/documents/{$second['document_id']}/content", ['Accept' => 'application/json'])->assertNotFound();

    // The learner's record shows the paper with the mark, once the marks are locked.
    expect(collect($this->actingAs($this->people['admin'])->getJson("/api/v1/assessment/learner/{$this->learners[0]}")->json('assessments'))->firstWhere('id', $id)['paper'])->toBe([]);
    assessmentLocked($this->people['tadala'], $id, [62, 48, 70]);
    $shown = collect($this->actingAs($this->people['admin'])->getJson("/api/v1/assessment/learner/{$this->learners[0]}")->json('assessments'))->firstWhere('id', $id);
    expect($shown['subject_id'])->toBe($this->subjects['BIO'])->and(array_column($shown['paper'], 'document_id'))->toBe([$second['document_id']]);
});

it('locks with four eyes, and returns to the teacher with a reason', function (): void {
    // The administrator sets, marks and submits: they may not lock their own marks.
    $own = assessmentSet($this->people['admin'], 'class_test', 'Fractions', 10, 'MAT');
    $this->actingAs($this->people['admin'])->putJson("/api/v1/assessment/assessments/{$own}/marks", ['marks' => assessmentMarks([8, 9, 'excused'])])->assertOk();
    $this->actingAs($this->people['admin'])->postJson("/api/v1/assessment/assessments/{$own}/submit")->assertOk();
    $this->actingAs($this->people['admin'])->getJson("/api/v1/assessment/assessments/{$own}")->assertOk()
        ->assertJsonPath('can_lock', false)->assertJsonPath('lock_note', 'You cannot lock marks you submitted.');
    $this->actingAs($this->people['admin'])->postJson("/api/v1/assessment/assessments/{$own}/lock")->assertForbidden()->assertJsonPath('code', 'another_person_required');
    $this->actingAs($this->people['mercy'])->postJson("/api/v1/assessment/assessments/{$own}/lock")->assertOk()->assertJsonPath('assessment.state', 'locked');

    $id = assessmentSet($this->people['tadala'], 'weekly_test', 'Weekly test 3', 20);
    $this->actingAs($this->people['tadala'])->putJson("/api/v1/assessment/assessments/{$id}/marks", ['marks' => assessmentMarks([15, 11, 19])])->assertOk();
    $this->actingAs($this->people['tadala'])->postJson("/api/v1/assessment/assessments/{$id}/submit")->assertOk();
    $this->actingAs($this->people['tadala'])->postJson("/api/v1/assessment/assessments/{$id}/lock")->assertForbidden();
    $this->actingAs($this->people['mercy'])->postJson("/api/v1/assessment/assessments/{$id}/return", ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->actingAs($this->people['mercy'])->postJson("/api/v1/assessment/assessments/{$id}/return", ['reason' => 'Check the mark of Dalitso Gondwe.'])->assertOk()
        ->assertJsonPath('assessment.state', 'returned')->assertJsonPath('history.1.reason', 'Check the mark of Dalitso Gondwe.');
    expect(DB::table('notification_requests')->where('topic_key', 'assessment.returned')->where('subject_id', $id)->count())->toBe(1);

    // Returned marks are open again; the teacher corrects and submits once more.
    $this->actingAs($this->people['tadala'])->putJson("/api/v1/assessment/assessments/{$id}/marks", ['marks' => assessmentMarks([15, 11, 9])])->assertOk()->assertJsonPath('saved', 1);
    $this->actingAs($this->people['tadala'])->postJson("/api/v1/assessment/assessments/{$id}/submit")->assertOk()->assertJsonPath('assessment.state', 'submitted');
    $this->actingAs($this->people['mercy'])->postJson("/api/v1/assessment/assessments/{$id}/lock")->assertOk()->assertJsonPath('assessment.state', 'locked');
    expect(DB::table('notification_requests')->where('topic_key', 'assessment.submitted')->where('subject_id', $id)->count())->toBe(2);

    // After locking, a change is a correction with a note, kept in the history.
    $this->actingAs($this->people['mercy'])->postJson("/api/v1/assessment/assessments/{$id}/correct", ['learner_id' => $this->learners[1], 'value' => 12])
        ->assertUnprocessable()->assertJsonValidationErrors(['note']);
    $this->actingAs($this->people['mercy'])->postJson("/api/v1/assessment/assessments/{$id}/correct", ['learner_id' => $this->learners[1], 'value' => 12, 'note' => 'Added wrongly.'])
        ->assertOk()->assertJsonPath('learners.1.value', 12)->assertJsonPath('learners.1.corrected', true);
    expect(DB::table('assessment_marks')->where('assessment_id', $id)->whereNotNull('replaces_id')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'assessment.mark_corrected')->value('reason'))->toBe('Added wrongly.');
});

it('works out the term grade from locked marks only, with the working, and grades it on the level scale', function (): void {
    $t = $this->people['tadala'];
    $w1 = assessmentSet($t, 'weekly_test', 'Weekly test 1', 20, heldOn: '2026-10-05');
    $w2 = assessmentSet($t, 'weekly_test', 'Weekly test 2', 20, heldOn: '2026-10-12');
    $mid = assessmentSet($t, 'mid_term', 'Mid-term test', 70, heldOn: '2026-10-30');
    $w3 = assessmentSet($t, 'weekly_test', 'Weekly test 3', 20, heldOn: '2026-11-02');
    $practice = assessmentSet($t, 'class_test', 'Practice', 10, counts: false, heldOn: '2026-11-03');
    $exam = assessmentSet($t, 'end_of_term', 'End-of-term exam', 100, heldOn: '2026-11-16');

    assessmentLocked($t, $w1, [16, 'excused', 10]);
    assessmentLocked($t, $w2, [14, 'absent', 10]);
    assessmentLocked($t, $mid, [54, 35, 35]);
    assessmentLocked($t, $w3, [17, 10, 10]);
    assessmentLocked($t, $practice, [1, 1, 1]);

    // The exam asks the teacher to decide about an absent learner before submitting.
    $this->actingAs($t)->putJson("/api/v1/assessment/assessments/{$exam}/marks", ['marks' => assessmentMarks([71, 50, 'absent'])])->assertOk();
    $this->actingAs($t)->postJson("/api/v1/assessment/assessments/{$exam}/submit")->assertUnprocessable()
        ->assertJsonPath('reason', '1 learner is marked absent, and the grading plan asks you to decide.');
    $this->actingAs($t)->putJson("/api/v1/assessment/assessments/{$exam}/marks", ['marks' => assessmentMarks([71, 50, 'excused'])])->assertOk();
    $this->actingAs($t)->postJson("/api/v1/assessment/assessments/{$exam}/submit")->assertOk();

    // Submitted is not locked: the grade is not ready.
    $results = $this->actingAs($this->people['admin'])->getJson("/api/v1/assessment/results?class_id={$this->class}")->assertOk();
    $bio = collect($results->json('subjects'))->firstWhere('short', 'BIO');
    expect($bio)->toMatchArray(['ready' => false, 'waiting' => 'A counted assessment is not locked yet: End-of-term exam.'])
        ->and($results->json('learners.0.grades.0'))->toMatchArray(['ready' => false, 'grade' => null]);

    $this->actingAs($this->people['mercy'])->postJson("/api/v1/assessment/assessments/{$exam}/lock")->assertOk();

    $working = fn (int $learner): array => $this->actingAs($this->people['admin'])->getJson('/api/v1/assessment/results/working?'.http_build_query([
        'class_id' => $this->class, 'learner_id' => $this->learners[$learner], 'subject_id' => $this->subjects['BIO'], 'period_id' => $this->terms['secondary']]))->assertOk()->json('working');

    // Alinafe: (80 + 70 + 77.1 + 85) / 4 = 78.0; 0.40 × 78.0 + 0.60 × 71 = 73.8: JCE B. The practice test is left out.
    $a = $working(0);
    expect($a)->toMatchArray(['ready' => true, 'percent' => 73.8, 'grade' => 'B', 'word' => 'Strong credit', 'passes' => true])
        ->and($a['plan'])->toMatchArray(['version' => 0, 'default' => true, 'mode' => 'continuous'])
        ->and(array_column($a['parts'], 'score', 'label'))->toEqual(['Continuous assessment' => 78, 'End-of-term exam' => 71])
        ->and(array_column($a['parts'][0]['assessments'], 'title'))->toBe(['Weekly test 1', 'Weekly test 2', 'Mid-term test', 'Weekly test 3'])
        ->and(array_column($a['parts'][0]['assessments'], 'percent'))->toEqual([80, 70, 77.1, 85]);

    // Chimwemwe: excused left out, absent without a reason counts zero: (0 + 50 + 50) / 3 = 33.3; 0.40 × 33.3 + 0.60 × 50 = 43.3: JCE D.
    $c = $working(1);
    expect($c)->toMatchArray(['percent' => 43.3, 'grade' => 'D'])
        ->and(array_column($c['parts'][0]['assessments'], 'note'))->toBe(['Excused: left out.', 'Absent without a reason: counted as zero.', null, null])
        ->and($c['parts'][0]['score'])->toBe(33.3);

    // Dalitso: excused from the exam, so the term's work alone makes the grade: 50.0, JCE D.
    expect($working(2))->toMatchArray(['percent' => 50.0, 'grade' => 'D'])->and($working(2)['parts'][1]['score'])->toBeNull();

    // Graded on MSCE points instead: 73.8 is a 2; lower is better, so the class shows the best six.
    $this->actingAs($this->people['admin'])->putJson('/api/v1/assessment/level-scales', ['division_id' => $this->school->id('secondary'), 'level_code' => 'F1', 'scale_id' => scaleId('msce')])->assertOk();
    $results = $this->actingAs($this->people['admin'])->getJson("/api/v1/assessment/results?class_id={$this->class}")->assertOk();
    expect($results->json('summary_kind'))->toBe('best_six')
        ->and(collect($results->json('subjects'))->firstWhere('short', 'BIO')['ready'])->toBeTrue()
        ->and(collect($results->json('subjects'))->firstWhere('short', 'MAT'))->toMatchArray(['ready' => false, 'waiting' => 'Continuous assessment has no locked work yet.'])
        ->and($results->json('learners.0.grades.0'))->toMatchArray(['grade' => '2', 'percent' => 73.8, 'ready' => true])
        ->and($results->json('learners.0.summary'))->toBe(['kind' => 'best_six', 'value' => 2, 'of' => 1])
        ->and($results->json('learners.1.below_pass'))->toBeFalse()
        ->and($results->json('totals'))->toBe(['learners' => 3, 'ranked' => 0, 'subjects' => 2, 'subjects_ready' => 1, 'below_pass' => 0])
        ->and($results->json('learners.0.position'))->toBeNull();

    // A correction to a locked mark works the grade out again.
    $this->actingAs($this->people['mercy'])->postJson("/api/v1/assessment/assessments/{$exam}/correct", ['learner_id' => $this->learners[0], 'value' => 91, 'note' => 'Page two was not counted.'])->assertOk();
    expect($working(0))->toMatchArray(['percent' => 85.8, 'grade' => '1', 'word' => 'Distinction']);

    // Percent and letters: the same 85.8 is an A.
    $this->actingAs($this->people['admin'])->putJson('/api/v1/assessment/level-scales', ['division_id' => $this->school->id('secondary'), 'level_code' => 'F1', 'scale_id' => scaleId('percent_letters')])->assertOk();
    expect($working(0))->toMatchArray(['grade' => 'A', 'word' => 'Excellent']);
});

it('ranks a class by total marks, with an average grade on the primary scale', function (): void {
    $this->actingAs($this->people['admin'])->putJson('/api/v1/assessment/level-scales', ['division_id' => $this->school->id('secondary'), 'level_code' => 'F1', 'scale_id' => scaleId('primary_1_4')])->assertOk();

    // Each subject: a weekly test (continuous, 40%) and the exam (60%).
    assessmentLocked($this->people['tadala'], assessmentSet($this->people['tadala'], 'weekly_test', 'Weekly test 1', 20), [16, 12, 16]);
    assessmentLocked($this->people['tadala'], assessmentSet($this->people['tadala'], 'end_of_term', 'End-of-term exam', 100), [80, 60, 70]);
    $results = $this->actingAs($this->people['admin'])->getJson("/api/v1/assessment/results?class_id={$this->class}")->assertOk();
    expect($results->json('totals.ranked'))->toBe(0); // Mathematics is not ready: nobody is ranked yet.

    assessmentLocked($this->people['admin'], assessmentSet($this->people['admin'], 'weekly_test', 'Weekly test 1', 20, 'MAT'), [10, 10, 10]);
    assessmentLocked($this->people['admin'], assessmentSet($this->people['admin'], 'end_of_term', 'End-of-term exam', 100, 'MAT'), [50, 90, 50]);

    // Biology 80, 60, 74; Mathematics 50, 74, 50. Totals 130, 134, 124.
    $results = $this->actingAs($this->people['admin'])->getJson("/api/v1/assessment/results?class_id={$this->class}")->assertOk();
    $row = fn (int $i): array => collect($results->json('learners'))->firstWhere('id', $this->learners[$i]);
    expect($results->json('summary_kind'))->toBe('average_grade')
        ->and($results->json('totals.ranked'))->toBe(3)
        ->and($row(1)['position'])->toBe(['position' => 1, 'of' => 3, 'shared' => false])
        ->and($row(0)['position'])->toBe(['position' => 2, 'of' => 3, 'shared' => false])
        ->and($row(2)['position'])->toBe(['position' => 3, 'of' => 3, 'shared' => false])
        ->and($row(0)['total'])->toEqual(['total' => 130, 'out_of' => 200, 'complete' => true])
        ->and(array_column($row(0)['grades'], 'grade'))->toBe(['4', '2'])
        ->and($row(0)['summary'])->toEqual(['kind' => 'average_grade', 'value' => 3.0, 'of' => 2])
        ->and($row(2)['summary'])->toEqual(['kind' => 'average_grade', 'value' => 2.5, 'of' => 2]);

    $mine = $this->actingAs($this->people['admin'])->getJson("/api/v1/assessment/learner/{$this->learners[0]}")->assertOk();
    expect($mine->json('position'))->toBe(['position' => 2, 'of' => 3, 'shared' => false]);
    $part = collect($this->actingAs($this->people['admin'])->getJson("/api/v1/learners/{$this->learners[0]}")->assertOk()->json('parts'))->firstWhere('key', 'assessment');
    expect(array_column($part['band'], 'value', 'label'))->toBe(['Position' => '2 of 3', 'Average grade' => '3.0']);
});

it('shares a position between equal totals and skips the next', function (): void {
    $t = fn (float $total, bool $complete = true): array => ['total' => $total, 'out_of' => 200, 'complete' => $complete];
    expect(TermGrades::positions(['a' => $t(130), 'b' => $t(134), 'c' => $t(130), 'd' => $t(120), 'e' => $t(150, false)]))->toBe([
        'b' => ['position' => 1, 'of' => 4, 'shared' => false],
        'a' => ['position' => 2, 'of' => 4, 'shared' => true],
        'c' => ['position' => 2, 'of' => 4, 'shared' => true],
        'd' => ['position' => 4, 'of' => 4, 'shared' => false],
    ]);
});

it('shows the results part on the learner record, with this term\'s assessments', function (): void {
    $id = assessmentSet($this->people['tadala'], 'end_of_term', 'End-of-term exam', 100);
    $open = assessmentSet($this->people['tadala'], 'weekly_test', 'Weekly test 4', 20, heldOn: '2026-11-12');
    assessmentLocked($this->people['tadala'], $id, [62, 48, 70]);

    $record = $this->actingAs($this->people['admin'])->getJson("/api/v1/learners/{$this->learners[0]}")->assertOk();
    $part = collect($record->json('parts'))->firstWhere('key', 'assessment');
    expect($part['title'])->toBe('Results')
        ->and(array_column($part['band'], 'label'))->toBe(['Position', 'Average'])
        ->and($part['band'][0]['value'])->toBe('–');

    $results = $this->actingAs($this->people['admin'])->getJson("/api/v1/assessment/learner/{$this->learners[0]}")->assertOk();
    expect(array_column($results->json('assessments'), 'title'))->toBe(['Weekly test 4', 'End-of-term exam'])
        ->and($results->json('assessments.0'))->toMatchArray(['locked' => false, 'value' => null])
        ->and($results->json('assessments.1'))->toMatchArray(['locked' => true, 'value' => 62, 'percent' => 62])
        ->and(collect($results->json('grades'))->firstWhere('subject', 'Biology'))->toMatchArray(['ready' => false, 'not_ready' => 'A counted assessment is not locked yet: Weekly test 4.']);
    expect($open)->toBeString();

    $this->actingAs($this->people['stranger'])->getJson("/api/v1/assessment/learner/{$this->learners[0]}")->assertNotFound();
});

it('lists assessments on the grid with filters, and locks in bulk, reporting each refusal', function (): void {
    $mine = assessmentSet($this->people['admin'], 'class_test', 'Fractions', 10, 'MAT');
    $this->actingAs($this->people['admin'])->putJson("/api/v1/assessment/assessments/{$mine}/marks", ['marks' => assessmentMarks([8, 9, 7])])->assertOk();
    $this->actingAs($this->people['admin'])->postJson("/api/v1/assessment/assessments/{$mine}/submit")->assertOk();
    $theirs = assessmentSet($this->people['tadala'], 'mid_term', 'Mid-term test', 50);
    $this->actingAs($this->people['tadala'])->putJson("/api/v1/assessment/assessments/{$theirs}/marks", ['marks' => assessmentMarks([40, 25, 'absent'])])->assertOk();
    $this->actingAs($this->people['tadala'])->postJson("/api/v1/assessment/assessments/{$theirs}/submit")->assertOk();
    $draft = assessmentSet($this->people['tadala'], 'weekly_test', 'Weekly test 4', 20, heldOn: '2026-11-12');

    $page = $this->actingAs($this->people['admin'])->getJson('/api/v1/assessment/grid?per_page=10&sort=held_on&dir=asc')->assertOk();
    expect($page->json('total'))->toBe(3)
        ->and($page->json('bulk'))->toBe([['key' => 'lock', 'label' => 'Lock the marks']])
        ->and(collect($page->json('rows'))->firstWhere('id', $theirs))->toMatchArray(['marked' => 3, 'learners' => 3, 'average' => 65.0, 'state' => 'submitted', 'teacher' => 'Tadala Mvula'])
        ->and(array_column(collect($page->json('filter_options'))->firstWhere('key', 'state')['options'], 'count', 'value'))->toMatchArray(['marking' => 1, 'submitted' => 2]);

    $submitted = $this->actingAs($this->people['admin'])->getJson('/api/v1/assessment/grid?filter[state]=submitted&filter[subject]='.$this->subjects['BIO'])->assertOk();
    expect(array_column($submitted->json('rows'), 'id'))->toBe([$theirs]);
    $this->actingAs($this->people['admin'])->getJson('/api/v1/assessment/grid?filter[kind]=weekly_test&filter[held]=2026-11-11..2026-11-13')->assertOk()->assertJsonPath('rows.0.id', $draft);

    $result = $this->actingAs($this->people['admin'])->postJson('/api/v1/grids/assessments/bulk', ['action' => 'lock', 'ids' => [$mine, $theirs, $draft], 'state' => []])->assertOk();
    expect($result->json('done'))->toBe(1)
        ->and(array_column($result->json('refused'), 'reason', 'id'))->toBe([$mine => 'You cannot lock marks you submitted.', $draft => 'The marks are not submitted.'])
        ->and(DB::table('assessments')->where('id', $theirs)->value('state'))->toBe('locked');

    $this->actingAs($this->people['tadala'])->getJson('/api/v1/assessment/grid')->assertOk()->assertJsonPath('bulk', []);
});

it('tells anyone without access that nothing is there', function (): void {
    $id = assessmentSet($this->people['tadala'], 'class_test', 'Cells', 20);

    $this->actingAs($this->people['stranger'])->getJson("/api/v1/assessment/assessments/{$id}")->assertNotFound();
    $this->actingAs($this->people['stranger'])->putJson("/api/v1/assessment/assessments/{$id}/marks", ['marks' => assessmentMarks([1, 2, 3])])->assertNotFound();
    $this->actingAs($this->people['stranger'])->getJson("/api/v1/assessment/results?class_id={$this->class}")->assertNotFound();
    $this->actingAs($this->people['stranger'])->getJson('/api/v1/assessment/grid')->assertForbidden();
    $this->actingAs($this->people['stranger'])->getJson('/api/v1/assessment/mine')->assertOk()->assertJsonPath('assessments', []);

    // The office sees the marks, but they are not its own to enter.
    $this->actingAs($this->people['mercy'])->getJson("/api/v1/assessment/assessments/{$id}")->assertOk()->assertJsonPath('can_enter', false);
    $this->actingAs($this->people['mercy'])->putJson("/api/v1/assessment/assessments/{$id}/marks", ['marks' => assessmentMarks([1, 2, 3])])->assertForbidden();

    // With the module off, nothing answers.
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'assessment', 'enabled' => false, 'reason' => 'Off.', 'switched_at' => now()]);
    app()->forgetScopedInstances();
    $this->actingAs($this->people['tadala'])->getJson("/api/v1/assessment/assessments/{$id}")->assertForbidden()->assertJsonPath('code', 'module_inactive');
});

it('publishes a ready class as a version with report cards, tells families, and republishes a correction', function (): void {
    $t = $this->people['tadala'];
    $martha = User::factory()->create(['name' => 'Martha Kalua']);
    $head = Role::query()->create(['key' => 'head_teacher', 'name' => 'Head teacher']);
    $head->grant([AssessmentPermission::Publish, AssessmentPermission::View]);
    RoleAssignment::query()->create(['user_id' => $martha->id, 'role_id' => $head->id, 'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    DB::table('academic_teaching')->insert(['id' => Ids::next(), 'class_id' => $this->class, 'subject_id' => null, 'user_id' => $t->id,
        'given_by' => $this->people['admin']->id, 'given_at' => now()->subMonth()]);

    $weekly = assessmentSet($t, 'weekly_test', 'Weekly test 1', 20);
    assessmentLocked($t, $weekly, [16, 12, 16]);
    assessmentLocked($t, assessmentSet($t, 'end_of_term', 'End-of-term exam', 100), [80, 60, 70]);

    // Mathematics has no work yet: the class is not ready.
    $row = fn (): array => collect($this->actingAs($martha)->getJson('/api/v1/assessment/publishing')->assertOk()->json('classes'))->firstWhere('class_id', $this->class);
    expect($row())->toMatchArray(['state' => 'not_ready', 'ready' => false, 'subjects' => 2, 'subjects_ready' => 1, 'learners' => 3, 'latest' => null])
        ->and($row()['missing'][0])->toStartWith('Mathematics: ');
    $this->actingAs($martha)->postJson('/api/v1/assessment/publishing', ['class_ids' => [$this->class]])->assertUnprocessable();

    assessmentLocked($this->people['admin'], assessmentSet($this->people['admin'], 'weekly_test', 'Weekly test 1', 20, 'MAT'), [10, 10, 10]);
    assessmentLocked($this->people['admin'], assessmentSet($this->people['admin'], 'end_of_term', 'End-of-term exam', 100, 'MAT'), [50, 90, 50]);

    // The class teacher writes the comment; only a publisher writes the head teacher's.
    $comment = fn (User $by, string $author, string $text) => $this->actingAs($by)->putJson('/api/v1/assessment/comments',
        ['class_id' => $this->class, 'learner_id' => $this->learners[0], 'author' => $author, 'text' => $text]);
    $comment($t, 'class_teacher', '  Works   steadily. ')->assertOk()->assertJsonPath('written', 1)->assertJsonPath('can_head', false);
    $comment($t, 'head', 'A good start.')->assertForbidden();
    $comment($martha, 'head', 'A good start.')->assertOk();
    $comment($t, 'class_teacher', str_repeat('a', 301))->assertUnprocessable();
    $this->actingAs($this->people['stranger'])->getJson("/api/v1/assessment/comments?class_id={$this->class}")->assertNotFound();
    $this->actingAs($this->people['stranger'])->postJson('/api/v1/assessment/publishing', ['class_ids' => [$this->class]])->assertNotFound();

    expect($row())->toMatchArray(['state' => 'ready', 'comments' => 1]);

    // Someone who submitted marks that count here does not publish them.
    $this->actingAs($this->people['admin'])->postJson('/api/v1/assessment/publishing', ['class_ids' => [$this->class]])
        ->assertForbidden()->assertJsonPath('code', 'another_person_required');

    $first = $this->actingAs($martha)->postJson('/api/v1/assessment/publishing', ['class_ids' => [$this->class]])->assertOk();
    $publication = $first->json('published.0.publication_id');
    expect($first->json('published.0.version'))->toBe(1)
        ->and($row())->toMatchArray(['state' => 'published'])
        ->and(DB::table('notification_deliveries')->where('topic_key', 'assessment.results_published')->count())->toBeGreaterThan(0);

    $cards = $this->actingAs($martha)->getJson("/api/v1/assessment/cards/{$publication}")->assertOk();
    $alinafe = collect($cards->json('cards'))->firstWhere('learner.id', $this->learners[0]);
    expect($cards->json('cards'))->toHaveCount(3)
        ->and($alinafe['reference'])->toMatch('/^R1-[A-Z0-9]{4}-[A-Z0-9]{4}$/')
        ->and($alinafe['position'])->toEqual(['position' => 2, 'of' => 3, 'shared' => false])
        ->and($alinafe['comments']['class_teacher']['text'])->toBe('Works steadily.')
        ->and($alinafe['comments']['head']['text'])->toBe('A good start.')
        ->and($alinafe)->not->toHaveKey('rank')
        ->and(array_column($alinafe['subjects'], 'percent'))->toEqual([80, 50]);
    $this->actingAs($this->people['stranger'])->getJson("/api/v1/assessment/cards/{$publication}")->assertNotFound();

    // Nothing changed: nothing to publish again.
    $this->actingAs($martha)->postJson('/api/v1/assessment/publishing', ['class_ids' => [$this->class]])->assertUnprocessable();

    // A corrected mark: the class shows changed, and the next version says what changed. Version 1 is kept.
    $this->actingAs($this->people['mercy'])->postJson("/api/v1/assessment/assessments/{$weekly}/correct",
        ['learner_id' => $this->learners[1], 'value' => 14, 'note' => 'Added up again.'])->assertOk();
    expect($row()['state'])->toBe('changed');
    $second = $this->actingAs($martha)->postJson('/api/v1/assessment/publishing', ['class_ids' => [$this->class]])->assertOk();
    expect($second->json('published.0.version'))->toBe(2)
        ->and(DB::table('result_publications')->where('class_id', $this->class)->orderByDesc('version')->value('reason'))->toBe('Changed: Biology (1 learner).')
        ->and(DB::table('result_publication_learners')->where('publication_id', $publication)->count())->toBe(3);

    $mine = $this->actingAs($martha)->getJson("/api/v1/assessment/learner/{$this->learners[1]}")->assertOk();
    expect($mine->json('published.version'))->toBe(2)
        ->and(array_column($mine->json('published.versions'), 'version'))->toBe([2, 1])
        ->and($mine->json('published.versions.0.by'))->toBe('Martha Kalua');

    // What the cards show is the school's choice, from the next version on.
    $this->actingAs($martha)->putJson('/api/v1/assessment/publishing/settings', ['show_position' => false, 'head_comment' => true])->assertOk()
        ->assertJsonPath('settings', ['show_position' => false, 'head_comment' => true]);
    $this->actingAs($t)->putJson('/api/v1/assessment/publishing/settings', ['show_position' => true, 'head_comment' => true])->assertForbidden();
});
