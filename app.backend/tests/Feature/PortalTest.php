<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Domain\GuardianLink;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\Jobs\SendSignInCodeText;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\PortalLink;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Notifications\ResultsPublished;
use Acadly\Modules\Attendance\Notifications\LearnerAbsent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fixtures\TwoCampusSchool;

/*
 * The parent portal (D-082): sign-in by phone and a code, the family found by
 * phone number and confirmed by the parent, and the office's Families list.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->withHeader('Origin', 'http://localhost:5173');
    $this->artisan('acadly:sync')->assertSuccessful();
    app()->forgetScopedInstances();
    RateLimiter::clear('sign-in-code:+265884771900');
    $this->school = TwoCampusSchool::make();

    foreach (['attendance', 'assessment'] as $module) {
        DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => $module, 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subMinute()]);
    }

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->staffWorkIn('division', $this->school->id('primary'));

    $admit = function (string $first, string $classKey, string $motherPhone, string $mother = 'Alice Jere'): string {
        return $this->actingAs($this->admin)->postJson('/api/v1/learners', [
            'first_name' => $first, 'other_names' => '', 'surname' => 'Jere', 'sex' => 'female', 'date_of_birth' => '2018-03-04',
            'class_id' => $this->school->id($classKey), 'admitted_on' => '2026-09-08', 'admission_number' => '',
            'contacts' => [
                ['name' => $mother, 'relationship' => 'mother', 'phone' => $motherPhone, 'email' => '', 'main' => true],
                ['name' => 'Daniel Jere', 'relationship' => 'father', 'phone' => '0999 120 334', 'email' => '', 'main' => false],
            ],
        ])->assertCreated()->json('id');
    };

    // Two children share the mother's number; a third child's mother has another number.
    $this->alinafe = $admit('Alinafe', 'class_primary_a', '0884 771 900');
    $this->chikondi = $admit('Chikondi', 'class_primary_b', '+265 884 771 900', 'Alice M. Jere');
    $this->other = $admit('Wezi', 'class_primary_a', '0881 000 111', 'Mary Phiri');
    Auth::forgetGuards();
});

/** Asks for a code and signs in with it; answers the parent's account. */
function parentSignsIn(object $test, string $phone = '0884 771 900'): User
{
    Queue::fake();
    $test->postJson('/api/v1/auth/phone/code', ['phone' => $phone])->assertOk()->assertExactJson(['sent' => true]);

    $code = null;
    Queue::assertPushed(SendSignInCodeText::class, function (SendSignInCodeText $job) use (&$code): bool {
        preg_match('/\b(\d{6})\b/', $job->text, $m);
        $code = $m[1] ?? null;

        return true;
    });

    $test->postJson('/api/v1/auth/phone/sign-in', ['phone' => $phone, 'code' => (string) $code])->assertOk();

    return User::query()->where('phone', '+265'.substr((string) preg_replace('/\D/', '', $phone), -9))->firstOrFail();
}

describe('signing in by phone', function (): void {
    it('sends a code to a number the school keeps and makes the account at the first sign-in', function (): void {
        $parent = parentSignsIn($this);

        expect($parent->name)->toBe('Alice Jere')
            ->and($parent->email)->toBeNull()
            ->and($this->actingAs($parent)->getJson('/api/v1/me')->assertOk()->json('user.phone'))->toBe('+265884771900')
            ->and($this->actingAs($parent)->getJson('/api/v1/me')->json('grants'))->toBe([]);
    });

    it('answers the same for a number the school does not keep, and sends nothing', function (): void {
        Queue::fake();

        $this->postJson('/api/v1/auth/phone/code', ['phone' => '0888 555 444'])->assertOk()->assertExactJson(['sent' => true]);

        Queue::assertNothingPushed();
        expect(DB::table('sign_in_codes')->where('phone', '+265888555444')->exists())->toBeFalse();
    });

    it('refuses a wrong code, and a code after five wrong tries', function (): void {
        Queue::fake();
        $this->postJson('/api/v1/auth/phone/code', ['phone' => '0884 771 900'])->assertOk();

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/auth/phone/sign-in', ['phone' => '0884 771 900', 'code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
        }

        $code = null;
        Queue::assertPushed(SendSignInCodeText::class, function (SendSignInCodeText $job) use (&$code): bool {
            preg_match('/\b(\d{6})\b/', $job->text, $m);
            $code = $m[1];

            return true;
        });

        $this->postJson('/api/v1/auth/phone/sign-in', ['phone' => '0884 771 900', 'code' => $code])->assertUnprocessable();
        expect(User::query()->where('phone', '+265884771900')->exists())->toBeFalse();
    });

    it('stores no code, only a keyed hash', function (): void {
        Queue::fake();
        $this->postJson('/api/v1/auth/phone/code', ['phone' => '0884 771 900'])->assertOk();

        $text = '';
        Queue::assertPushed(SendSignInCodeText::class, function (SendSignInCodeText $job) use (&$text): bool {
            $text = $job->text;

            return true;
        });
        preg_match('/\b(\d{6})\b/', $text, $m);

        expect(DB::table('sign_in_codes')->where('phone', '+265884771900')->value('code_hash'))->not->toContain($m[1]);
    });
});

describe('the family', function (): void {
    it('proposes every child whose contact has the number, and shows nothing until the parent confirms', function (): void {
        $parent = parentSignsIn($this);
        $family = $this->actingAs($parent)->getJson('/api/v1/portal/family')->assertOk()->json();

        expect(collect($family['to_confirm'])->pluck('name')->sort()->values()->all())->toBe(['Alinafe Jere', 'Chikondi Jere'])
            ->and($family['children'])->toBe([]);

        $this->actingAs($parent)->getJson("/api/v1/portal/children/{$this->alinafe}/card")->assertNotFound();
        $this->actingAs($parent)->getJson("/api/v1/portal/children/{$this->alinafe}/attendance")->assertNotFound();
        $this->actingAs($parent)->getJson("/api/v1/portal/children/{$this->alinafe}/messages")->assertNotFound();
    });

    it('links the children the parent confirms, and hides the child that is not theirs', function (): void {
        $parent = parentSignsIn($this);

        $family = $this->actingAs($parent)->postJson('/api/v1/portal/family/confirm', ['mine' => [$this->alinafe], 'not_mine' => [$this->chikondi]])->assertOk()->json();

        expect(collect($family['children'])->pluck('name')->all())->toBe(['Alinafe Jere'])
            ->and($family['to_confirm'])->toBe([]);

        $this->actingAs($parent)->getJson("/api/v1/portal/children/{$this->alinafe}/attendance")->assertOk()->assertJsonPath('child.first_name', 'Alinafe');
        $this->actingAs($parent)->getJson("/api/v1/portal/children/{$this->alinafe}/card")->assertOk()->assertJsonPath('card', null);
        $this->actingAs($parent)->getJson("/api/v1/portal/children/{$this->chikondi}/card")->assertNotFound();
    });

    it('refuses a child the school does not propose for the number', function (): void {
        $parent = parentSignsIn($this);

        $this->actingAs($parent)->postJson('/api/v1/portal/family/confirm', ['mine' => [$this->other], 'not_mine' => []])->assertUnprocessable();
        $this->actingAs($parent)->getJson("/api/v1/portal/children/{$this->other}/card")->assertNotFound();
    });

    it('lets only the parent the link belongs to confirm it', function (): void {
        $parent = parentSignsIn($this);
        $this->actingAs($parent)->postJson('/api/v1/portal/family/confirm', ['mine' => [], 'not_mine' => [$this->chikondi]])->assertOk();
        $link = DB::table('learner_guardian_relationships')->where('learner_id', $this->chikondi)->value('id');

        $this->actingAs($this->admin)->postJson("/api/v1/families/links/{$link}", ['action' => 'recheck', 'reason' => 'The mother called the office.'])->assertOk();

        expect(DB::table('learner_guardian_relationships')->where('id', $link)->value('state'))->toBe('requested');

        $engine = app(JourneyEngine::class);
        $model = GuardianLink::query()->findOrFail($link);

        expect(fn () => $engine->perform($model, $this->admin, 'confirm', 'confirmed'))->toThrow(AuthorizationException::class);
    });
});

describe('the office', function (): void {
    it('groups learners by phone, shows who uses the portal and what needs checking', function (): void {
        $parent = parentSignsIn($this);
        $this->actingAs($parent)->postJson('/api/v1/portal/family/confirm', ['mine' => [$this->alinafe], 'not_mine' => [$this->chikondi]])->assertOk();

        $data = $this->actingAs($this->admin)->getJson('/api/v1/families')->assertOk()->json();
        $first = $data['families'][0];

        expect($first['phone'])->toBe('0884 771 900')
            ->and($first['names'])->toBe(['Alice Jere', 'Alice M. Jere'])
            ->and($first['portal']['signed_in'])->toBeTrue()
            ->and(collect($first['checks'])->pluck('kind')->all())->toBe(['not_their_child', 'names_differ'])
            ->and($data['totals'])->toMatchArray(['in_portal' => 1, 'to_check' => 1]);
    });

    it('suspends a link at once, with a reason, and refuses someone without the permission', function (): void {
        $parent = parentSignsIn($this);
        $this->actingAs($parent)->postJson('/api/v1/portal/family/confirm', ['mine' => [$this->alinafe], 'not_mine' => []])->assertOk();
        $link = DB::table('learner_guardian_relationships')->where('learner_id', $this->alinafe)->value('id');

        $clerk = User::factory()->create();
        $this->actingAs($clerk)->getJson('/api/v1/families')->assertForbidden();
        $this->actingAs($clerk)->postJson("/api/v1/families/links/{$link}", ['action' => 'suspend', 'reason' => 'Court order.'])->assertForbidden();
        $this->actingAs($this->admin)->postJson("/api/v1/families/links/{$link}", ['action' => 'suspend', 'reason' => ''])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson("/api/v1/families/links/{$link}", ['action' => 'suspend', 'reason' => 'Court order.'])->assertOk();

        $this->actingAs($parent)->getJson("/api/v1/portal/children/{$this->alinafe}/card")->assertNotFound();
        expect($this->actingAs($parent)->getJson('/api/v1/portal/family')->json('children.0.state'))->toBe('suspended');
    });

    it('is a sight that names who should hold it', function (): void {
        expect(EnrolmentPermission::Families->isSight())->toBeTrue()
            ->and(EnrolmentPermission::Families->help())->toStartWith('SEES EVERY FAMILY')
            ->and(Ids::isValid($this->alinafe))->toBeTrue();
    });
});

describe('bringing families in', function (): void {
    it('ends the results message with the portal address, and leaves absence messages as they are', function (): void {
        config(['acadly.portal_url' => 'https://sunrise.acadly.mw/p/']);

        $results = ResultsPublished::notice();
        $absent = LearnerAbsent::notice();

        expect(PortalLink::after($results, 'Ready.'))->toBe('Ready. See it on your phone: sunrise.acadly.mw/p')
            ->and(PortalLink::after($absent, 'Absent.'))->toBe('Absent.')
            ->and($this->actingAs($this->admin, 'web')->getJson('/api/v1/messages/notices/assessment.results_published')->assertOk()->json('portal_line'))
            ->toBe('See it on your phone: sunrise.acadly.mw/p');

        config(['acadly.portal_url' => '']);
        expect(PortalLink::after($results, 'Ready.'))->toBe('Ready.');
    });

    it('invites a family once a week by one text message, and passes over a family already in the portal', function (): void {
        config(['acadly.portal_url' => 'https://sunrise.acadly.mw/p']);

        $answer = $this->actingAs($this->admin, 'web')->postJson('/api/v1/families/invite', ['phones' => ['0884 771 900', '0881 000 111', '0888 555 444']])->assertOk()->json();
        $sms = DB::table('notification_deliveries')->where('topic_key', 'enrolment.portal_invitation')->where('channel', 'sms')->get();

        expect($answer['invited'])->toBe(2)
            ->and($sms)->toHaveCount(2)
            ->and($sms->pluck('recipient_phone')->sort()->values()->all())->toBe(['+265881000111', '+265884771900'])
            ->and((string) $sms->first()?->short)->toEndWith('See it on your phone: sunrise.acadly.mw/p')
            ->and((string) $sms->first()?->short)->not->toContain('Alinafe');

        $family = collect($answer['families']['families'])->firstWhere('phone', '0884 771 900');
        expect($family['portal']['invited_at'])->not->toBeNull()
            ->and($family['portal']['may_invite'])->toBeFalse();

        expect($this->actingAs($this->admin, 'web')->postJson('/api/v1/families/invite', ['phones' => ['0884 771 900']])->json('invited'))->toBe(0);

        $this->travel(8)->days();
        parentSignsIn($this);
        expect($this->actingAs($this->admin)->postJson('/api/v1/families/invite', ['phones' => ['0884 771 900', '0881 000 111']])->assertOk()->json('invited'))->toBe(1);
    });

    it('refuses invitations from someone who does not keep families', function (): void {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/v1/families/invite', ['phones' => ['0884 771 900']])->assertForbidden();
        expect(DB::table('portal_invitations')->count())->toBe(0);
    });

    it('shows on a contact the other learners it is also the contact of', function (): void {
        $contacts = $this->actingAs($this->admin, 'web')->getJson("/api/v1/learners/{$this->alinafe}")->assertOk()->json('contacts');
        $mother = collect($contacts)->firstWhere('name', 'Alice Jere');
        $father = collect($contacts)->firstWhere('name', 'Daniel Jere');

        expect(collect($mother['also'])->pluck('name')->all())->toBe(['Chikondi Jere'])
            ->and(collect($father['also'])->pluck('name')->sort()->values()->all())->toBe(['Chikondi Jere', 'Wezi Jere'])
            ->and($mother['in_portal'])->toBeFalse();

        $parent = parentSignsIn($this);
        $this->actingAs($parent)->postJson('/api/v1/portal/family/confirm', ['mine' => [$this->alinafe, $this->chikondi], 'not_mine' => []])->assertOk();
        $after = $this->actingAs($this->admin)->getJson("/api/v1/learners/{$this->alinafe}")->json('contacts');

        expect(collect($after)->firstWhere('name', 'Alice Jere')['in_portal'])->toBeTrue()
            ->and(collect($after)->firstWhere('name', 'Daniel Jere')['in_portal'])->toBeFalse();
    });
});
