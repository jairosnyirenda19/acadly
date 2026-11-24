<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\Jobs\SendSignInCodeText;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Application\Observations;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\SafeRelease\Contracts\SafeReleasePermission;
use Acadly\Modules\SafeRelease\Jobs\SendCollectorCodeText;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * A nursery (D-094): observations by learning area, progress reviews checked
 * by someone else and shared with families, and safe pick-up.
 */

uses(DatabaseTransactions::class);

/** @param  list<Permission>  $permissions */
function nurseryStaff(ScopeRef $at, array $permissions, string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $role = Role::query()->create(['key' => 'ecd_'.substr(Ids::next(), -12), 'name' => $name]);
    $role->grant($permissions);
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $at->type, 'scope_id' => $at->id, 'starts_at' => now()->subMinute()]);

    return $user;
}

function nurseryParent(object $test, string $phone): User
{
    Auth::forgetGuards();
    Queue::fake();
    $test->postJson('/api/v1/auth/phone/code', ['phone' => $phone])->assertOk();
    $code = null;
    Queue::assertPushed(SendSignInCodeText::class, function (SendSignInCodeText $job) use (&$code): bool {
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

beforeEach(function (): void {
    $this->withHeader('Origin', 'http://localhost:5173');
    $this->travelTo('2026-11-19 10:00:00');
    $this->artisan('acadly:sync')->assertSuccessful();

    foreach (['assessment', 'saferelease'] as $module) {
        DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => $module, 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    }

    app()->forgetScopedInstances();
    $now = now();
    $this->division = Ids::next();
    DB::table('institution_divisions')->insert(['id' => $this->division, 'name' => 'Nursery', 'profile_ref' => 'ECD@2026.1', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $campus = Ids::next();
    DB::table('institution_campuses')->insert(['id' => $campus, 'name' => 'Area 47', 'place' => 'Lilongwe', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('institution_offerings')->insert(['id' => Ids::next(), 'division_id' => $this->division, 'campus_id' => $campus, 'started_at' => $now]);
    $year = Ids::next();
    DB::table('academic_years')->insert(['id' => $year, 'name' => '2026/27', 'starts_on' => '2026-09-07', 'ends_on' => '2027-07-23', 'created_at' => $now, 'updated_at' => $now]);
    $this->room = Ids::next();
    DB::table('academic_classes')->insert(['id' => $this->room, 'year_id' => $year, 'division_id' => $this->division, 'campus_id' => $campus, 'level_code' => 'TOP',
        'stream' => null, 'name' => 'Sunflower Room', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $this->period = Ids::next();
    DB::table('academic_periods')->insert(['id' => $this->period, 'year_id' => $year, 'division_id' => $this->division, 'name' => 'Term 1', 'position' => 1,
        'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => $now, 'updated_at' => $now]);
    $this->areas = [];

    foreach (['TAL' => 'Talking and listening', 'NUM' => 'Early numbers'] as $short => $name) {
        $this->areas[$short] = Ids::next();
        DB::table('academic_subjects')->insert(['id' => $this->areas[$short], 'division_id' => $this->division, 'short' => $short, 'name' => $name,
            'core' => true, 'levels' => json_encode(['TOP']), 'position' => count($this->areas), 'created_at' => $now, 'updated_at' => $now]);
    }

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);

    $admit = function (string $first, string $phone): string {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/learners', [
            'first_name' => $first, 'other_names' => '', 'surname' => 'Phiri', 'sex' => 'female', 'date_of_birth' => '2022-05-02',
            'class_id' => $this->room, 'admitted_on' => '2026-09-07', 'admission_number' => '',
            'contacts' => [['name' => 'Esther Phiri', 'relationship' => 'mother', 'phone' => $phone, 'email' => '', 'main' => true],
                ['name' => 'Daniel Phiri', 'relationship' => 'father', 'phone' => '0999 120 334', 'email' => '', 'main' => false]],
        ]);
        expect($response->status())->toBe(201, (string) $response->getContent());

        return (string) $response->json('id');
    };

    $this->thoko = $admit('Thokozani', '0884 771 900');
    $this->wana = $admit('Wanangwa', '0884 771 901');
    $at = ScopeRef::of(ScopeType::ClassGroup, $this->room);
    $this->caregiver = nurseryStaff($at, [EnrolmentPermission::View, AssessmentPermission::Observe, SafeReleasePermission::Release], 'Ruth Mkandawire');
    $this->head = nurseryStaff($at, [EnrolmentPermission::View, AssessmentPermission::Observe, AssessmentPermission::Review, SafeReleasePermission::Manage], 'Mary Chirwa');
    $this->outsider = nurseryStaff(ScopeRef::of(ScopeType::Institution, null), [EnrolmentPermission::View], 'Peter Banda');
});

describe('observations', function (): void {
    it('records a step for the children seen, and counts them by learning area this week', function (): void {
        $this->actingAs($this->caregiver)->postJson("/api/v1/assessment/rooms/{$this->room}/observations", [
            'subject_id' => $this->areas['NUM'], 'observed_on' => '2026-11-19', 'focus' => 'Counting to ten',
            'marks' => [['learner_id' => $this->thoko, 'step' => 'can_do', 'note' => 'Counted ten blocks alone.'], ['learner_id' => $this->wana, 'step' => 'not_seen']],
        ])->assertOk()->assertJsonPath('recorded', 2);

        $room = $this->actingAs($this->caregiver)->getJson("/api/v1/assessment/rooms/{$this->room}")->assertOk()->json();
        $num = collect($room['areas'])->firstWhere('id', $this->areas['NUM']);

        expect($num['seen'])->toBe(1)
            ->and(collect($room['children'])->firstWhere('id', $this->wana)['seen_this_week'])->toBeFalse()
            ->and(collect($room['children'])->firstWhere('id', $this->thoko)['seen_this_week'])->toBeTrue()
            ->and($room['can'])->toBe(['observe' => true, 'review' => false]);

        $day = $this->actingAs($this->caregiver)->getJson("/api/v1/assessment/rooms/{$this->room}/observations?subject_id={$this->areas['NUM']}&day=2026-11-19")->assertOk()->json();
        expect($day['focus'])->toBe('Counting to ten')
            ->and(collect($day['children'])->firstWhere('id', $this->thoko)['step'])->toBe('can_do');
    });

    it('refuses a day in the future, a child of another room and a step it does not know', function (): void {
        $post = fn (array $body) => $this->actingAs($this->caregiver)->postJson("/api/v1/assessment/rooms/{$this->room}/observations",
            $body + ['subject_id' => $this->areas['TAL'], 'observed_on' => '2026-11-19']);

        $post(['observed_on' => '2026-11-20', 'marks' => [['learner_id' => $this->thoko, 'step' => 'growing']]])->assertUnprocessable()->assertJsonValidationErrors(['observed_on']);
        $post(['marks' => [['learner_id' => Ids::next(), 'step' => 'growing']]])->assertUnprocessable()->assertJsonValidationErrors(['marks']);
        $post(['marks' => [['learner_id' => $this->thoko, 'step' => '7']]])->assertUnprocessable()->assertJsonValidationErrors(['marks']);
    });

    it('never sets marks for a level that records observations', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/assessment/assessments', [
            'class_id' => $this->room, 'subject_id' => $this->areas['NUM'], 'kind' => 'class_test', 'title' => 'Counting', 'held_on' => '2026-11-19', 'out_of' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors(['class_id']);
    });

    it('tells someone who does not work in the room that it does not exist', function (): void {
        $this->actingAs($this->outsider)->getJson("/api/v1/assessment/rooms/{$this->room}")->assertNotFound();
        $this->actingAs($this->outsider)->getJson('/api/v1/assessment/rooms')->assertOk()->assertJsonPath('rooms', []);
    });

    it('names the record tab Observations for a nursery child, with the days observed', function (): void {
        $this->actingAs($this->caregiver)->postJson("/api/v1/assessment/rooms/{$this->room}/observations", [
            'subject_id' => $this->areas['TAL'], 'observed_on' => '2026-11-18', 'marks' => [['learner_id' => $this->thoko, 'step' => 'growing']],
        ])->assertOk();

        $parts = collect($this->actingAs($this->head)->getJson("/api/v1/learners/{$this->thoko}")->assertOk()->json('parts'));
        $part = $parts->firstWhere('key', 'assessment');

        expect($part['title'])->toBe('Observations')
            ->and($part['band'][0]['value'])->toBe('1')
            ->and($part['band'][1]['value'])->toBe('Not started');
    });
});

describe('progress reviews', function (): void {
    it('is drafted, sent to be checked, checked by someone else and shared with the family', function (): void {
        $this->actingAs($this->caregiver)->putJson("/api/v1/assessment/reviews/learner/{$this->thoko}", [
            'steps' => [$this->areas['TAL'] => 'growing'], 'family_note' => '', 'home_note' => '',
        ])->assertOk();
        $id = $this->actingAs($this->caregiver)->getJson("/api/v1/assessment/reviews/learner/{$this->thoko}")->assertOk()->json('review.id');

        // A step is missing in Early numbers, and the note for the family is empty.
        $this->actingAs($this->caregiver)->postJson("/api/v1/assessment/reviews/{$id}/submit")->assertStatus(422);

        $this->actingAs($this->caregiver)->putJson("/api/v1/assessment/reviews/learner/{$this->thoko}", [
            'steps' => [$this->areas['TAL'] => 'growing', $this->areas['NUM'] => 'can_do'], 'family_note' => 'Thokozani counts ten blocks alone.', 'home_note' => 'Count the cups at supper.',
        ])->assertOk();
        $this->actingAs($this->caregiver)->postJson("/api/v1/assessment/reviews/{$id}/submit")->assertOk();

        // No changes once it waits to be checked.
        $this->actingAs($this->caregiver)->putJson("/api/v1/assessment/reviews/learner/{$this->thoko}", ['steps' => [], 'family_note' => 'Changed.'])->assertUnprocessable();

        // The caregiver may not check; the head checks, then shares.
        $this->actingAs($this->caregiver)->postJson("/api/v1/assessment/reviews/{$id}/check")->assertForbidden();
        $this->actingAs($this->head)->postJson("/api/v1/assessment/reviews/{$id}/check")->assertOk();

        // Nothing for the family before it is shared.
        $parent = nurseryParent($this, '0884 771 900');
        $this->actingAs($parent)->getJson("/api/v1/portal/children/{$this->thoko}/review")->assertOk()->assertJsonPath('review', null);

        $this->actingAs($this->head)->postJson('/api/v1/assessment/reviews/share', ['ids' => [$id]])->assertOk()->assertJsonPath('shared', 1);
        expect(DB::table('notification_requests')->where('topic_key', 'assessment.review_shared')->where('subject_id', $id)->count())->toBe(1);

        $shared = $this->actingAs($parent)->getJson("/api/v1/portal/children/{$this->thoko}/review")->assertOk()->json('review');
        expect($shared['family_note'])->toBe('Thokozani counts ten blocks alone.')
            ->and(array_column($shared['areas'], 'label'))->toBe(['Growing', 'Can do it'])
            ->and($shared)->not->toHaveKey('drafted_by');

        // Another family's child is not found.
        $this->actingAs($parent)->getJson("/api/v1/portal/children/{$this->wana}/review")->assertNotFound();

        // D-107: the shared review as a PDF with its check code; another family's child is not found.
        $pdf = $this->actingAs($parent)->get("/api/v1/portal/children/{$this->thoko}/review/pdf")->assertOk();
        expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF')
            ->and(DB::table('document_checks')->where('kind', 'assessment.progress_review')->count())->toBe(1);
        $this->actingAs($parent)->get("/api/v1/portal/children/{$this->wana}/review/pdf")->assertNotFound();
    });

    it('uses the school\'s words for the steps, saved by someone who plans, and keeps the strokes', function (): void {
        $words = ['en' => ['starting' => 'Beginning', 'growing' => 'Getting there', 'can_do' => 'Can do it'], 'ny' => ['starting' => 'Akuyamba', 'growing' => '', 'can_do' => '']];
        $this->actingAs($this->caregiver)->putJson('/api/v1/assessment/step-words', $words)->assertForbidden();
        $this->actingAs($this->admin)->putJson('/api/v1/assessment/step-words', ['en' => ['starting' => 'Same', 'growing' => 'Same', 'can_do' => 'Done']])->assertUnprocessable();
        $this->actingAs($this->admin)->putJson('/api/v1/assessment/step-words', $words)->assertOk();
        $this->actingAs($this->caregiver)->getJson('/api/v1/assessment/step-words')->assertForbidden();
        expect($this->actingAs($this->admin)->getJson('/api/v1/assessment/step-words')->assertOk()->json())->toMatchArray(['en' => $words['en']])
            ->and(DB::table('observation_step_words')->count())->toBe(1);
        // The caregiver's screens and the family see the school's words; the strokes are the same.
        $shared = Observations::steps();
        expect(collect($shared)->firstWhere('key', 'starting'))->toMatchArray(['label' => 'Beginning', 'strokes' => 1]);
    });

    it('may not be checked by the person who sent it to be checked', function (): void {
        $this->actingAs($this->head)->putJson("/api/v1/assessment/reviews/learner/{$this->wana}", [
            'steps' => [$this->areas['TAL'] => 'starting', $this->areas['NUM'] => 'growing'], 'family_note' => 'Wanangwa is settling in well.',
        ])->assertOk();
        $id = $this->actingAs($this->head)->getJson("/api/v1/assessment/reviews/learner/{$this->wana}")->json('review.id');
        $this->actingAs($this->head)->postJson("/api/v1/assessment/reviews/{$id}/submit")->assertOk();

        $this->actingAs($this->head)->postJson("/api/v1/assessment/reviews/{$id}/check")->assertStatus(403);
        $this->actingAs($this->head)->postJson("/api/v1/assessment/reviews/{$id}/return", ['reason' => 'Add a note for home.'])->assertOk();
        expect($this->actingAs($this->head)->getJson("/api/v1/assessment/reviews/learner/{$this->wana}")->json('review.state'))->toBe('draft');
    });

    it('lists every child of the room with the state of the review', function (): void {
        $list = $this->actingAs($this->head)->getJson('/api/v1/assessment/reviews')->assertOk()->json();

        expect($list['totals']['children'])->toBe(2)
            ->and($list['totals']['started'])->toBe(0)
            ->and($list['can_review'])->toBeTrue()
            ->and($list['children'][0]['state_label'])->toBe('Not started');
    });
});

describe('safe pick-up', function (): void {
    it('sends the one-day code only to the phone the family gave, never as a kept message', function (): void {
        Queue::fake();
        $parent = nurseryParent($this, '0884 771 900');
        $added = $this->actingAs($parent)->postJson("/api/v1/portal/children/{$this->thoko}/collectors", ['name' => 'Grace Phiri', 'relationship' => 'Aunt',
            'phone' => '0991 234 567', 'send_code' => true])->assertCreated()->json();
        expect($added['sent_to'])->toBe('0991 234 567')->and(DB::table('notification_deliveries')->where('body', 'like', '%'.$added['code'].'%')->count())->toBe(0);
        Queue::assertPushed(SendCollectorCodeText::class,
            fn ($job): bool => $job->phone === '0991 234 567' && str_contains($job->text, $added['code']) && str_contains($job->text, 'Thokozani'));

        // Without a phone, or without asking, nothing is sent.
        $none = $this->actingAs($parent)->postJson("/api/v1/portal/children/{$this->thoko}/collectors", ['name' => 'John Phiri', 'relationship' => 'Uncle', 'send_code' => true])->assertCreated()->json();
        expect($none['sent_to'])->toBeNull();
        Queue::assertPushed(SendCollectorCodeText::class, 1);
    });

    it('shows who the gate marked as arrived, and releases that arrival at the desk', function (): void {
        $child = $this->actingAs($this->caregiver)->getJson("/api/v1/pickup/learners/{$this->thoko}")->assertOk()->json();
        $mother = collect($child['may_collect'])->firstWhere('name', 'Esther Phiri');

        $this->actingAs($this->caregiver)->postJson("/api/v1/pickup/learners/{$this->thoko}/arrive", ['kind' => 'contact', 'collector_id' => $mother['id']])->assertCreated();
        $this->actingAs($this->caregiver)->postJson("/api/v1/pickup/learners/{$this->thoko}/arrive", ['kind' => 'contact', 'collector_id' => $mother['id']])->assertUnprocessable();
        $desk = $this->actingAs($this->caregiver)->getJson('/api/v1/pickup')->assertOk()->json();
        expect($desk['totals']['at_gate'])->toBe(1)->and($desk['at_gate'][0])->toMatchArray(['child' => 'Thokozani Phiri', 'name' => 'Esther Phiri', 'needs_code' => false])
            ->and(DB::table('pickups')->where('learner_id', $this->thoko)->value('state'))->toBe('announced');

        $this->actingAs($this->caregiver)->postJson("/api/v1/pickup/learners/{$this->thoko}/release", ['kind' => 'contact', 'collector_id' => $mother['id']])->assertOk();
        expect(DB::table('pickups')->where('learner_id', $this->thoko)->count())->toBe(1)
            ->and(DB::table('pickups')->where('learner_id', $this->thoko)->value('state'))->toBe('released')
            ->and($this->actingAs($this->caregiver)->getJson('/api/v1/pickup')->json('totals.at_gate'))->toBe(0);
    });

    it('releases a child to a contact, records it and tells the family', function (): void {
        $child = $this->actingAs($this->caregiver)->getJson("/api/v1/pickup/learners/{$this->thoko}")->assertOk()->json();
        $mother = collect($child['may_collect'])->firstWhere('name', 'Esther Phiri');

        $this->actingAs($this->caregiver)->postJson("/api/v1/pickup/learners/{$this->thoko}/release", ['kind' => 'contact', 'collector_id' => $mother['id']])->assertOk();

        $desk = $this->actingAs($this->caregiver)->getJson('/api/v1/pickup')->assertOk()->json();
        expect($desk['totals'])->toBe(['children' => 2, 'collected' => 1, 'here' => 1, 'at_gate' => 0])
            ->and(collect($desk['children'])->firstWhere('id', $this->thoko)['collected']['name'])->toBe('Esther Phiri')
            ->and(DB::table('notification_requests')->where('topic_key', 'saferelease.released')->count())->toBe(1);

        // Once collected, not again.
        $this->actingAs($this->caregiver)->postJson("/api/v1/pickup/learners/{$this->thoko}/release", ['kind' => 'contact', 'collector_id' => $mother['id']])->assertUnprocessable();
    });

    it('closes the arrival the gate marked when the desk refuses the person', function (): void {
        $mother = collect($this->actingAs($this->caregiver)->getJson("/api/v1/pickup/learners/{$this->thoko}")->json('may_collect'))->firstWhere('name', 'Esther Phiri');
        $this->actingAs($this->caregiver)->postJson("/api/v1/pickup/learners/{$this->thoko}/arrive", ['kind' => 'contact', 'collector_id' => $mother['id']])->assertCreated();
        $this->actingAs($this->caregiver)->postJson("/api/v1/pickup/learners/{$this->thoko}/refuse", ['name' => 'Esther Phiri', 'note' => 'Did not look like the photo on file.'])->assertOk();

        expect(DB::table('pickups')->where('learner_id', $this->thoko)->count())->toBe(1)
            ->and(DB::table('pickups')->where('learner_id', $this->thoko)->value('state'))->toBe('disputed')
            ->and($this->actingAs($this->caregiver)->getJson('/api/v1/pickup')->json('totals.at_gate'))->toBe(0);
    });

    it('records a refusal for someone not on the list and tells the family', function (): void {
        $this->actingAs($this->caregiver)->postJson("/api/v1/pickup/learners/{$this->thoko}/refuse", ['name' => 'Peter Gondwe, says he is a neighbour'])->assertOk();

        expect(DB::table('pickups')->where('learner_id', $this->thoko)->value('state'))->toBe('disputed')
            ->and(DB::table('notification_requests')->where('topic_key', 'saferelease.refused')->count())->toBe(1);
    });

    it('never releases to a contact recorded as not to collect', function (): void {
        $father = collect($this->actingAs($this->head)->getJson("/api/v1/pickup/learners/{$this->thoko}")->json('contacts'))->firstWhere('name', 'Daniel Phiri');

        // The caregiver may release but may not keep the list.
        $this->actingAs($this->caregiver)->postJson("/api/v1/pickup/learners/{$this->thoko}/bars", ['contact_id' => $father['id'], 'reason' => 'Court order.'])->assertForbidden();
        $this->actingAs($this->head)->postJson("/api/v1/pickup/learners/{$this->thoko}/bars", ['contact_id' => $father['id'], 'reason' => 'Court order.'])->assertOk();

        $this->actingAs($this->caregiver)->postJson("/api/v1/pickup/learners/{$this->thoko}/release", ['kind' => 'contact', 'collector_id' => $father['id']])
            ->assertUnprocessable()->assertJsonValidationErrors(['collector']);
    });

    it('lets the family name someone for today, released only with the code', function (): void {
        $parent = nurseryParent($this, '0884 771 900');
        $added = $this->actingAs($parent)->postJson("/api/v1/portal/children/{$this->thoko}/collectors", ['name' => 'Agnes Banda', 'relationship' => 'Aunt'])->assertCreated()->json();

        expect($added['code'])->toMatch('/^\d{4}$/')
            ->and(DB::table('release_collectors')->where('id', $added['id'])->value('code_hash'))->not->toBe($added['code']);

        $this->actingAs($parent)->getJson("/api/v1/portal/children/{$this->wana}/pickup")->assertNotFound();
        $portal = $this->actingAs($parent)->getJson("/api/v1/portal/children/{$this->thoko}/pickup")->assertOk()->json();
        expect(collect($portal['may_collect'])->firstWhere('name', 'Agnes Banda')['day_only'])->toBeTrue();

        $wrong = $added['code'] === '0000' ? '1111' : '0000';
        $this->actingAs($this->caregiver)->postJson("/api/v1/pickup/learners/{$this->thoko}/release", ['kind' => 'collector', 'collector_id' => $added['id'], 'code' => $wrong])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->actingAs($this->caregiver)->postJson("/api/v1/pickup/learners/{$this->thoko}/release", ['kind' => 'collector', 'collector_id' => $added['id'], 'code' => $added['code']])->assertOk();

        // The family's card says who collected the child, and offers the progress review instead of a report card.
        $lines = collect($this->actingAs($parent)->getJson('/api/v1/portal/family')->assertOk()->json('children'))->firstWhere('id', $this->thoko)['lines'];
        expect(collect($lines)->firstWhere('key', 'pickup')['word'])->toBe('collected')
            ->and(collect($lines)->firstWhere('key', 'pickup')['label'])->toContain('Agnes Banda')
            ->and(collect($lines)->firstWhere('key', 'review')['word'])->toBe('no_review')
            ->and(collect($lines)->firstWhere('key', 'card'))->toBeNull();

        // The next day the one-day collector is gone.
        $this->travelTo('2026-11-20 10:00:00');
        $names = array_column($this->actingAs($this->caregiver)->getJson("/api/v1/pickup/learners/{$this->thoko}")->json('may_collect'), 'name');
        expect($names)->not->toContain('Agnes Banda');
    });

    it('tells someone who does not work with the child that it does not exist', function (): void {
        $this->actingAs($this->outsider)->getJson("/api/v1/pickup/learners/{$this->thoko}")->assertNotFound();
        $this->actingAs($this->outsider)->getJson('/api/v1/pickup')->assertForbidden();
    });
});
