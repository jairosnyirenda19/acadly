<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Contracts\AboutLearner;
use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

it('gives every module that keeps something about a learner a part of the learner record', function (): void {
    $parts = array_map(fn (LearnerRecordPart $p): string => $p->key(), iterator_to_array(app()->tagged(LearnerRecordPart::TAG), false));

    foreach (app(ModuleRegistry::class)->all() as $module) {
        $aboutLearners = array_filter($module->topics(), fn (string $topic): bool => is_subclass_of($topic, AboutLearner::class));

        if ($aboutLearners !== []) {
            expect(in_array($module->key(), $parts, true))->toBeTrue("The module [{$module->key()}] tells families about learners, so it must add its part to the learner record (LearnerRecordPart).");
        }
    }
});

it('shows a learner\'s attendance, the family\'s messages and the band on the record', function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    app()->forgetScopedInstances();
    $this->travelTo('2026-11-17 08:20:00');
    $school = TwoCampusSchool::make();

    foreach (['primary', 'secondary'] as $division) {
        DB::table('academic_periods')->insert(['id' => Ids::next(), 'year_id' => $school->id('year'), 'division_id' => $school->id($division),
            'name' => 'Term 1', 'position' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);
    }

    $admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'), 'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $class = $school->id('class_secondary_b');
    $ids = [];

    foreach ([['Chimwemwe', 'Chirwa'], ['Dalitso', 'Gondwe']] as [$first, $surname]) {
        $ids[] = $this->actingAs($admin)->postJson('/api/v1/learners', ['first_name' => $first, 'surname' => $surname, 'sex' => 'male', 'date_of_birth' => '2012-05-02', 'class_id' => $class, 'admitted_on' => '2026-09-08',
            'contacts' => [['name' => 'Ruth '.$surname, 'relationship' => 'mother', 'phone' => '0991 234 567', 'main' => true], ['name' => 'Peter '.$surname, 'relationship' => 'father', 'phone' => '0888 410 220']]])->assertCreated()->json('id');
    }

    $marks = fn (array $s): array => array_map(fn (string $id, string $st): array => ['learner_id' => $id, 'status' => $st, 'note' => null], $ids, $s);
    $this->actingAs($admin)->postJson("/api/v1/registers/class/{$class}", ['day' => '2026-11-16', 'marks' => $marks(['late', 'present'])])->assertOk();
    $this->actingAs($admin)->postJson("/api/v1/registers/class/{$class}", ['day' => '2026-11-17', 'marks' => $marks(['absent', 'present'])])->assertOk();

    $record = $this->actingAs($admin)->getJson("/api/v1/learners/{$ids[0]}")->assertOk();
    expect(array_column($record->json('parts'), 'key'))->toBe(['attendance'])
        ->and(array_column($record->json('parts.0.band'), 'value', 'label'))->toMatchArray(['Present this term' => '50%', 'Absent' => '1', 'Late' => '1'])
        ->and($record->json('told.all'))->toBe(1);

    $attendance = $this->actingAs($admin)->getJson("/api/v1/registers/learner/{$ids[0]}")->assertOk();
    expect($attendance->json('term.name'))->toBe('Term 1')
        ->and($attendance->json('absences.0'))->toMatchArray(['day' => '2026-11-17', 'what' => 'absent'])
        ->and($attendance->json('absences.0.told.0.to'))->toBe('Ruth Chirwa');
    $week = collect($attendance->json('weeks'))->firstWhere('week', '2026-11-16')['days'];
    expect(array_column($week, 'mark'))->toBe(['late', 'absent', null, null, null]);

    $messages = $this->actingAs($admin)->getJson("/api/v1/learners/{$ids[0]}/messages")->assertOk();
    expect($messages->json('messages.0'))->toMatchArray(['notice' => 'Learner absent', 'to' => 'Ruth Chirwa']);
    $this->actingAs($admin)->getJson("/api/v1/learners/{$ids[1]}/messages")->assertOk()->assertJsonPath('messages', []);

    // Someone who may not see the learner sees nothing of it.
    $this->actingAs(User::factory()->create())->getJson("/api/v1/registers/learner/{$ids[0]}")->assertNotFound();
});
