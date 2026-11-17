<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\Jobs\SendSignInCodeText;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fixtures\TwoCampusSchool;

/*
 * A family gives the reason for a morning absence in the parent portal
 * (D-084). The reason is kept beside the absence; the mark never changes.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->withHeader('Origin', 'http://localhost:5173');
    $this->artisan('acadly:sync')->assertSuccessful();
    app()->forgetScopedInstances();
    $this->travelTo('2026-11-17 10:00:00'); // a Tuesday in Term 1
    RateLimiter::clear('sign-in-code:+265884771900');
    RateLimiter::clear('sign-in-code:+265881000111');
    Queue::fake();
    $this->school = TwoCampusSchool::make();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subMinute()]);

    foreach (['primary', 'secondary'] as $division) {
        DB::table('academic_periods')->insert(['id' => Ids::next(), 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id($division),
            'name' => 'Term 1', 'position' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->class = $this->school->id('class_primary_a');

    $admit = fn (string $first, string $phone, string $mother, string $fatherPhone): string => $this->actingAs($this->admin)->postJson('/api/v1/learners', [
        'first_name' => $first, 'other_names' => '', 'surname' => 'Banda', 'sex' => 'female', 'date_of_birth' => '2018-03-04', 'class_id' => $this->class,
        'admitted_on' => '2026-09-08', 'admission_number' => '', 'contacts' => [
            ['name' => $mother, 'relationship' => 'mother', 'phone' => $phone, 'email' => '', 'main' => true],
            ['name' => 'Peter Banda', 'relationship' => 'father', 'phone' => $fatherPhone, 'email' => '', 'main' => false],
        ],
    ])->assertCreated()->json('id');

    $this->chikondi = $admit('Chikondi', '0884 771 900', 'Ruth Banda', '0888 410 220');
    $this->wezi = $admit('Wezi', '0881 000 111', 'Mary Phiri', '0888 410 221');

    $mark = fn (string $day, string $chikondi): mixed => $this->actingAs($this->admin)->postJson("/api/v1/registers/class/{$this->class}", ['day' => $day, 'marks' => [
        ['learner_id' => $this->chikondi, 'status' => $chikondi, 'note' => null],
        ['learner_id' => $this->wezi, 'status' => 'present', 'note' => null],
    ]])->assertOk();

    $mark('2026-11-02', 'absent'); // more than 14 days ago
    $mark('2026-11-16', 'present');
    $mark('2026-11-17', 'absent'); // today
    Auth::forgetGuards();
});

/** Signs in by phone and confirms every child proposed for the number. */
function familySignsIn(object $test, string $phone): User
{
    $test->postJson('/api/v1/auth/phone/code', ['phone' => $phone])->assertOk();

    $code = null;
    Queue::assertPushed(SendSignInCodeText::class, function (SendSignInCodeText $job) use (&$code, $phone): bool {
        if (! str_ends_with($job->phone, substr((string) preg_replace('/\D/', '', $phone), -9))) {
            return false;
        }

        preg_match('/\b(\d{6})\b/', $job->text, $m);
        $code = $m[1] ?? null;

        return true;
    });

    $test->postJson('/api/v1/auth/phone/sign-in', ['phone' => $phone, 'code' => (string) $code])->assertOk();
    $parent = User::query()->where('phone', '+265'.substr((string) preg_replace('/\D/', '', $phone), -9))->firstOrFail();
    $family = $test->actingAs($parent)->getJson('/api/v1/portal/family')->assertOk()->json('to_confirm');
    $test->actingAs($parent)->postJson('/api/v1/portal/family/confirm', ['mine' => array_column($family, 'id'), 'not_mine' => []])->assertOk();

    return $parent;
}

it('asks for a reason today, keeps the reason beside the absence, and leaves the mark absent', function (): void {
    $parent = familySignsIn($this, '0884 771 900');
    $today = fn (): array => collect($this->actingAs($parent)->getJson('/api/v1/portal/family')->json('children.0.lines'))->firstWhere('key', 'today');

    expect($today()['word'])->toBe('absent_ask');

    $attendance = $this->actingAs($parent)->postJson("/api/v1/portal/children/{$this->chikondi}/absences/2026-11-17/reason", ['kind' => 'sick', 'note' => 'Fever since Monday night.'])
        ->assertOk()->json('attendance.absences');

    expect($attendance)->toHaveCount(2)
        ->and($attendance[0])->toMatchArray(['day' => '2026-11-17', 'open' => true])
        ->and($attendance[0]['reason'])->toMatchArray(['kind' => 'sick', 'note' => 'Fever since Monday night.', 'by' => 'Ruth Banda', 'by_me' => true])
        ->and($attendance[1])->toMatchArray(['day' => '2026-11-02', 'open' => false, 'reason' => null])
        ->and($today()['word'])->toBe('absent');

    $register = $this->actingAs($this->admin)->getJson("/api/v1/registers/class/{$this->class}?day=2026-11-17")->assertOk()->json('learners');
    $row = collect($register)->firstWhere('id', $this->chikondi);

    expect($row['mark']['status'])->toBe('absent')
        ->and($row['absence']['from_family'])->toMatchArray(['kind' => 'sick', 'note' => 'Fever since Monday night.', 'by' => 'Ruth Banda'])
        ->and(DB::table('audit_events')->where('action', 'attendance.absence_reason_given')->count())->toBe(1);

    $record = $this->actingAs($this->admin)->getJson("/api/v1/registers/learner/{$this->chikondi}")->assertOk()->json('absences');
    expect(collect($record)->firstWhere('day', '2026-11-17')['from_family']['kind'])->toBe('sick');
});

it('shows staff the latest reason and keeps every reason', function (): void {
    $parent = familySignsIn($this, '0884 771 900');
    $url = "/api/v1/portal/children/{$this->chikondi}/absences/2026-11-17/reason";

    $this->actingAs($parent)->postJson($url, ['kind' => 'sick', 'note' => ''])->assertOk();
    $this->actingAs($parent)->postJson($url, ['kind' => 'family', 'note' => 'A funeral in the village.'])->assertOk();

    $row = collect($this->actingAs($this->admin)->getJson("/api/v1/registers/class/{$this->class}?day=2026-11-17")->json('learners'))->firstWhere('id', $this->chikondi);

    expect($row['absence']['from_family'])->toMatchArray(['kind' => 'family', 'note' => 'A funeral in the village.'])
        ->and(DB::table('attendance_absence_reasons')->where('learner_id', $this->chikondi)->count())->toBe(2);
});

it('refuses a child that is not the parent\'s, a day that was not absent, an old day, and an unknown reason', function (): void {
    $parent = familySignsIn($this, '0884 771 900');
    $other = familySignsIn($this, '0881 000 111');
    $url = fn (string $learner, string $day): string => "/api/v1/portal/children/{$learner}/absences/{$day}/reason";

    $this->actingAs($other)->postJson($url($this->chikondi, '2026-11-17'), ['kind' => 'sick'])->assertNotFound();
    $this->actingAs($parent)->postJson($url($this->chikondi, '2026-11-16'), ['kind' => 'sick'])->assertUnprocessable()->assertJsonValidationErrors(['day']);
    $this->actingAs($parent)->postJson($url($this->chikondi, '2026-11-02'), ['kind' => 'sick'])->assertUnprocessable()->assertJsonValidationErrors(['day']);
    $this->actingAs($parent)->postJson($url($this->chikondi, '2026-11-18'), ['kind' => 'sick'])->assertNotFound();
    $this->actingAs($parent)->postJson($url($this->chikondi, '2026-11-17'), ['kind' => 'holiday'])->assertUnprocessable()->assertJsonValidationErrors(['kind']);
    $this->actingAs($parent)->postJson($url($this->chikondi, '2026-11-17'), ['kind' => 'other', 'note' => str_repeat('x', 256)])->assertUnprocessable()->assertJsonValidationErrors(['note']);

    expect(DB::table('attendance_absence_reasons')->count())->toBe(0);
});

it('lets staff accept the reason: a correction to excused that the family sees once approved', function (): void {
    $parent = familySignsIn($this, '0884 771 900');
    $register = (string) DB::table('attendance_registers')->where('day', '2026-11-17')->value('id');
    $accept = "/api/v1/registers/{$register}/absences/{$this->chikondi}/accept";
    $head = User::factory()->create(['name' => 'Agnes Chilembwe']);
    RoleAssignment::query()->create(['user_id' => $head->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);

    $this->actingAs($this->admin)->postJson($accept)->assertUnprocessable()->assertJsonValidationErrors(['learner_id']);
    $this->actingAs($parent)->postJson("/api/v1/portal/children/{$this->chikondi}/absences/2026-11-17/reason", ['kind' => 'sick', 'note' => 'Fever.'])->assertOk();

    $row = collect($this->actingAs($this->admin)->postJson($accept)->assertOk()->json('learners'))->firstWhere('id', $this->chikondi);

    expect($row['mark']['status'])->toBe('absent')
        ->and($row['correction'])->toMatchArray(['from' => 'absent', 'to' => 'excused', 'state' => 'requested'])
        ->and($row['correction']['reason'])->toBe('Reason from the family: Sick or seeing a doctor. “Fever.” Given by Ruth Banda.');

    $this->actingAs($this->admin)->postJson($accept)->assertUnprocessable();

    $this->actingAs($head)->postJson("/api/v1/registers/corrections/{$row['correction']['id']}/decide", ['approve' => true])->assertOk();

    $absences = $this->actingAs($parent)->getJson("/api/v1/portal/children/{$this->chikondi}/attendance")->assertOk()->json('attendance');

    expect($absences['totals']['absent'])->toBe(1)
        ->and($absences['absences'][0])->toMatchArray(['day' => '2026-11-17', 'open' => false])
        ->and($absences['absences'][0]['excused_at'])->not->toBeNull()
        ->and($absences['absences'][0]['reason']['kind'])->toBe('sick');

    $record = $this->actingAs($this->admin)->getJson("/api/v1/registers/learner/{$this->chikondi}")->assertOk()->json('absences');
    expect(collect($record)->firstWhere('day', '2026-11-17')['what'])->toBe('excused');

    // The reason cannot change once the day is excused.
    $this->actingAs($parent)->postJson("/api/v1/portal/children/{$this->chikondi}/absences/2026-11-17/reason", ['kind' => 'family'])->assertUnprocessable();
});
