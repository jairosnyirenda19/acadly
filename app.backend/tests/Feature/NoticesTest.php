<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoCampusSchool;

/*
 * Notices to families (D-115): the office writes a notice for the whole
 * school, a division, a level or a class; families of those learners read it
 * in the portal; an optional text goes once to each family; a withdrawn
 * notice leaves the portal.
 */

uses(DatabaseTransactions::class);

/** @param  list<EnrolmentPermission>  $permissions */
function noticeStaff(ScopeRef $at, array $permissions, string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $role = Role::query()->create(['key' => 'ntc_'.substr(Ids::next(), -12), 'name' => $name]);
    $role->grant($permissions);
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $at->type, 'scope_id' => $at->id, 'starts_at' => now()->subMinute()]);

    return $user;
}

beforeEach(function (): void {
    $this->travelTo('2026-11-25 10:00:00');
    $this->artisan('acadly:sync')->assertSuccessful();
    $this->school = TwoCampusSchool::make();
    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->staffWorkIn('division', $this->school->id('primary'));

    $admit = function (string $first, string $class, string $phone): string {
        return (string) $this->actingAs($this->admin)->postJson('/api/v1/learners', [
            'first_name' => $first, 'other_names' => '', 'surname' => 'Mwale', 'sex' => 'female', 'date_of_birth' => '2016-05-02',
            'class_id' => $this->school->id($class), 'admitted_on' => '2026-09-07', 'admission_number' => '',
            'contacts' => [['name' => 'Ruth Mwale', 'relationship' => 'mother', 'phone' => $phone, 'email' => '', 'main' => true],
                ['name' => 'Peter Mwale', 'relationship' => 'father', 'phone' => '0999 120 334', 'email' => '', 'main' => false]],
        ])->assertCreated()->json('id');
    };
    // Two sisters share their mother's phone; a third family; a learner in Secondary.
    $this->tiwonge = $admit('Tiwonge', 'class_primary_a', '0884 771 901');
    $this->kondwani = $admit('Kondwani', 'class_primary_a', '0884 771 901');
    $this->wezi = $admit('Wezi', 'class_primary_a', '0881 000 111');

    $this->parent = User::factory()->create(['name' => 'Ruth Mwale', 'email' => null, 'phone' => '+265884771901']);
    $guardian = Ids::next();
    DB::table('guardians')->insert(['id' => $guardian, 'phone' => '+265884771901', 'name' => 'Ruth Mwale', 'user_id' => $this->parent->id, 'created_at' => now()]);
    DB::table('learner_guardian_relationships')->insert(['id' => Ids::next(), 'guardian_id' => $guardian, 'learner_id' => $this->tiwonge,
        'contact_id' => (string) DB::table('learner_contacts')->where('learner_id', $this->tiwonge)->where('relationship', 'mother')->value('id'), 'held_by' => $this->parent->id, 'state' => 'active',
        'journey_ref' => 'enrolment.guardian_link@2026.1', 'created_at' => now(), 'updated_at' => now()]);
    $this->teacher = noticeStaff($this->school->scope(ScopeType::ClassGroup, 'class_primary_a'), [EnrolmentPermission::View, EnrolmentPermission::Notify], 'Peter Banda');
    $this->notice = fn (array $more = []): array => ['audience' => 'division', 'division_id' => $this->school->id('primary'), 'title' => 'School closes early on Friday',
        'body' => 'School closes at 12:00 on Friday 4 December for the staff meeting.', ...$more];
    Auth::forgetGuards();
});

it('says who a notice reaches, sends one text to each family, and counts the families who read it', function (): void {
    $reach = $this->actingAs($this->admin)->getJson('/api/v1/notices/reach?audience=division&division_id='.$this->school->id('primary'))->assertOk();
    expect($reach->json())->toBe(['learners' => 3, 'families' => 2, 'in_portal' => 1, 'texts' => 2]);

    $id = $this->actingAs($this->admin)->postJson('/api/v1/notices', ($this->notice)(['sms_text' => 'School closes at 12:00 on Fri 4 Dec.']))->assertCreated()->json('id');
    expect(DB::table('notification_requests')->where('topic_key', 'enrolment.family_notice')->where('subject_id', $id)->count())->toBe(1)
        ->and(DB::table('notification_deliveries')->where('topic_key', 'enrolment.family_notice')->count())->toBe(2);
    Auth::forgetGuards();

    $mine = $this->actingAs($this->parent)->getJson('/api/v1/portal/notices')->assertOk()->json('notices');
    expect(array_column($mine, 'id'))->toBe([$id])->and($mine[0]['new'])->toBeTrue();
    $this->actingAs($this->parent)->getJson("/api/v1/portal/notices/{$id}")->assertOk()->assertJsonPath('new', false);
    Auth::forgetGuards();

    $row = collect($this->actingAs($this->admin)->getJson('/api/v1/notices')->assertOk()->json('notices'))->firstWhere('id', $id);
    expect($row)->toMatchArray(['for' => 'Primary', 'read' => 1, 'families' => 2]);
});

it('keeps a notice to the families of its class or level, and to those who may write there', function (): void {
    // The class teacher writes to their class, not to the division.
    $this->actingAs($this->teacher)->postJson('/api/v1/notices', ($this->notice)())->assertForbidden();
    $class = $this->actingAs($this->teacher)->postJson('/api/v1/notices', ($this->notice)(['audience' => 'class', 'class_id' => $this->school->id('class_primary_a')]))->assertCreated()->json('id');
    // Working in Primary, the walls keep out a Secondary class; working in Secondary, it goes.
    $this->actingAs($this->admin)->postJson('/api/v1/notices', ($this->notice)(['audience' => 'class', 'class_id' => $this->school->id('class_secondary_b'), 'title' => 'Form 2 trip']))->assertForbidden();
    $this->staffWorkIn('division', $this->school->id('secondary'));
    $other = $this->actingAs($this->admin)->postJson('/api/v1/notices', ($this->notice)(['audience' => 'class', 'class_id' => $this->school->id('class_secondary_b'), 'title' => 'Form 2 trip']))->assertCreated()->json('id');
    $this->staffWorkIn('division', $this->school->id('primary'));
    $this->actingAs($this->admin)->postJson('/api/v1/notices', ($this->notice)(['audience' => 'level', 'level_code' => 'NOPE']))->assertUnprocessable()->assertJsonValidationErrors(['level_code']);
    Auth::forgetGuards();

    expect(array_column($this->actingAs($this->parent)->getJson('/api/v1/portal/notices')->json('notices'), 'id'))->toBe([$class]);
    $this->actingAs($this->parent)->getJson("/api/v1/portal/notices/{$other}")->assertNotFound();
});

it('sends a text only with a notice published now, within the room left after the school name', function (): void {
    $this->actingAs($this->admin)->postJson('/api/v1/notices', ($this->notice)(['sms_text' => 'Closes early.', 'publish_at' => '2026-11-27 08:00:00']))
        ->assertUnprocessable()->assertJsonValidationErrors(['sms_text']);
    $this->actingAs($this->admin)->postJson('/api/v1/notices', ($this->notice)(['sms_text' => str_repeat('x', 155)]))->assertUnprocessable()->assertJsonValidationErrors(['sms_text']);

    // Scheduled for later: not in the portal yet.
    $later = $this->actingAs($this->admin)->postJson('/api/v1/notices', ($this->notice)(['publish_at' => '2026-11-27 08:00:00']))->assertCreated()->json('id');
    Auth::forgetGuards();
    expect($this->actingAs($this->parent)->getJson('/api/v1/portal/notices')->json('notices'))->toBe([]);
    $this->travelTo('2026-11-27 09:00:00');
    expect(array_column($this->actingAs($this->parent)->getJson('/api/v1/portal/notices')->json('notices'), 'id'))->toBe([$later]);
});

it('withdraws a notice with a reason: it leaves the portal at once', function (): void {
    $id = $this->actingAs($this->admin)->postJson('/api/v1/notices', ($this->notice)())->assertCreated()->json('id');
    $this->actingAs($this->admin)->postJson("/api/v1/notices/{$id}/withdraw", ['reason' => ''])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("/api/v1/notices/{$id}/withdraw", ['reason' => 'The date was wrong.'])->assertOk();
    $this->actingAs($this->admin)->postJson("/api/v1/notices/{$id}/withdraw", ['reason' => 'Again.'])->assertUnprocessable();
    expect(collect($this->actingAs($this->admin)->getJson('/api/v1/notices')->json('notices'))->firstWhere('id', $id)['withdrawn']['reason'])->toBe('The date was wrong.');
    Auth::forgetGuards();

    expect($this->actingAs($this->parent)->getJson('/api/v1/portal/notices')->json('notices'))->toBe([]);
});
