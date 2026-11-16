<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Channels\NoSmsProvider;
use Acadly\Kernel\Notify\MessageSettings;
use Acadly\Kernel\Notify\Sms\AfricasTalkingProvider;
use Acadly\Kernel\Notify\Sms\LogSmsProvider;
use Acadly\Kernel\Notify\Sms\MeTicketProvider;
use Acadly\Kernel\Notify\Sms\SmsLength;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Phone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoCampusSchool;

uses(DatabaseTransactions::class);

const MSG_TODAY = '2026-11-13'; // a Friday in Term 1

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();
    DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'attendance', 'enabled' => true, 'reason' => 'Test setup.', 'switched_at' => now()->subYear()]);
    app()->forgetScopedInstances();
    $this->travelTo('2026-11-13 06:30:00'); // 08:30 in Blantyre
    LogSmsProvider::$sent = [];
    $this->school = TwoCampusSchool::make();

    foreach (['primary', 'secondary'] as $division) {
        DB::table('academic_periods')->insert(['id' => Ids::next(), 'year_id' => $this->school->id('year'), 'division_id' => $this->school->id($division),
            'name' => 'Term 1', 'position' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11', 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->admin = User::factory()->create(['name' => 'Grace Phiri']);
    RoleAssignment::query()->create(['user_id' => $this->admin->id, 'role_id' => Role::query()->where('is_system', true)->value('id'),
        'scope_type' => ScopeType::Institution, 'scope_id' => null, 'starts_at' => now()->subMinute()]);
    $this->class = $this->school->id('class_primary_a');

    // Chikondi's mother reads email; Thoko's mother takes SMS; Madalitso is present.
    $this->learners = [];

    foreach ([['Chikondi', 'Banda', 'ruth.banda@sunrise.test.mw', 'email'], ['Thoko', 'Chisale', null, 'sms'], ['Madalitso', 'Dzonzi', null, 'sms']] as [$first, $surname, $email, $by]) {
        $this->learners[] = $this->actingAs($this->admin)->postJson('/api/v1/learners', [
            'first_name' => $first, 'surname' => $surname, 'sex' => 'female', 'date_of_birth' => '2019-05-02', 'class_id' => $this->class, 'admitted_on' => '2026-09-08',
            'contacts' => [['name' => 'Ruth '.$surname, 'relationship' => 'mother', 'phone' => '0991 234 567', 'email' => $email, 'notices_by' => $by, 'main' => true],
                ['name' => 'Peter '.$surname, 'relationship' => 'father', 'phone' => '0888 410 220']],
        ])->assertCreated()->json('id');
    }
});

function msgMarks(array $learners, array $statuses): array
{
    return array_map(fn (string $id, string $status): array => ['learner_id' => $id, 'status' => $status, 'note' => null], $learners, $statuses);
}

function msgRoute(string $token = 'reports0123456789reports0123456789'): string
{
    DB::table('notification_routes')->insert(['channel' => 'sms', 'provider' => 'meticket', 'receipt_token' => $token, 'updated_at' => now()]);

    return $token;
}

it('tells the main contact of each absent learner on the day, by the channel the contact chose', function (): void {
    $register = $this->actingAs($this->admin)->postJson("/api/v1/registers/class/{$this->class}", ['day' => MSG_TODAY, 'marks' => msgMarks($this->learners, ['absent', 'absent', 'present'])])
        ->assertOk()->json();

    $deliveries = DB::table('notification_deliveries')->where('topic_key', 'attendance.learner_absent')->orderBy('recipient_name')->get();

    expect($deliveries->pluck('channel', 'recipient_name')->all())->toBe(['Ruth Banda' => 'email', 'Ruth Chisale' => 'sms'])
        ->and(LogSmsProvider::$sent)->toHaveCount(1)
        ->and(LogSmsProvider::$sent[0]['to'])->toBe('+265991234567')
        ->and(LogSmsProvider::$sent[0]['text'])->toContain('Thoko Chisale was marked absent from')
        ->and($register['learners'][0]['absence']['told'][0])->toMatchArray(['to' => 'Ruth Banda', 'relationship' => 'Mother', 'channel' => 'email', 'state' => 'accepted'])
        ->and($register['learners'][1]['absence']['next'])->toBe('Peter Chisale')
        ->and($register['learners'][2]['absence'])->toBeNull();

    // A register for an earlier day tells nobody.
    $this->actingAs($this->admin)->postJson("/api/v1/registers/class/{$this->class}", ['day' => '2026-11-12', 'marks' => msgMarks($this->learners, ['absent', 'absent', 'absent'])])->assertOk();

    expect(DB::table('notification_deliveries')->where('topic_key', 'attendance.learner_absent')->count())->toBe(2);
});

it('settles a message from the provider report, tells the next contact, and records a call', function (): void {
    $token = msgRoute();
    $registerId = $this->actingAs($this->admin)->postJson("/api/v1/registers/class/{$this->class}", ['day' => MSG_TODAY, 'marks' => msgMarks($this->learners, ['present', 'absent', 'present'])])
        ->assertOk()->json('register.id');
    $ref = DB::table('notification_deliveries')->where('topic_key', 'attendance.learner_absent')->value('provider_ref');

    // A report to the wrong address changes nothing; the right one settles the message, and nothing undoes a delivery.
    $this->postJson('/api/v1/notify/reports/wrongtoken0123456789', ['ref' => $ref, 'stat' => 'UNDELIV'])->assertNoContent();
    expect(DB::table('notification_deliveries')->where('provider_ref', $ref)->value('status'))->toBe('sent');
    $this->postJson("/api/v1/notify/reports/{$token}", ['ref' => $ref, 'stat' => 'UNDELIV'])->assertNoContent();
    expect(DB::table('notification_deliveries')->where('provider_ref', $ref)->value('status'))->toBe('failed');

    $learner = $this->learners[1];
    $after = $this->actingAs($this->admin)->postJson("/api/v1/registers/{$registerId}/absences/{$learner}/next")->assertOk()->json('learners.1.absence');

    expect(collect($after['told'])->pluck('state', 'to')->all())->toBe(['Ruth Chisale' => 'not_delivered', 'Peter Chisale' => 'sent'])
        ->and($after['next'])->toBeNull()
        ->and(LogSmsProvider::$sent[1]['to'])->toBe('+265888410220');

    $ref2 = DB::table('notification_deliveries')->where('topic_key', 'attendance.learner_absent_next')->value('provider_ref');
    $this->postJson("/api/v1/notify/reports/{$token}", ['ref' => $ref2, 'stat' => 'DELIVRD'])->assertNoContent();
    $this->postJson("/api/v1/notify/reports/{$token}", ['ref' => $ref2, 'stat' => 'UNDELIV'])->assertNoContent();
    expect(DB::table('notification_deliveries')->where('provider_ref', $ref2)->value('status'))->toBe('delivered');

    $called = $this->actingAs($this->admin)->postJson("/api/v1/registers/{$registerId}/absences/{$learner}/called", ['note' => 'Spoke to the father.'])->assertOk()->json('learners.1.absence.follow_ups');

    expect(collect($called)->pluck('kind')->all())->toBe(['told_next', 'called'])
        ->and($called[1]['note'])->toBe('Spoke to the father.');
    $this->actingAs($this->admin)->postJson("/api/v1/registers/{$registerId}/absences/{$this->learners[0]}/called")->assertUnprocessable();
});

it('keeps the access key encrypted, and never shows it again', function (): void {
    $this->actingAs($this->admin)->putJson('/api/v1/messages/channels/sms', ['provider' => 'meticket', 'sender' => 'SUNRISE'])
        ->assertUnprocessable()->assertJsonValidationErrors(['key']);
    $this->actingAs($this->admin)->putJson('/api/v1/messages/channels/sms', ['provider' => 'meticket', 'sender' => 'SUNRISE ACADEMY', 'key' => 'k'])
        ->assertUnprocessable()->assertJsonValidationErrors(['sender']);

    $saved = $this->actingAs($this->admin)->putJson('/api/v1/messages/channels/sms', ['provider' => 'meticket', 'sender' => 'SUNRISE', 'key' => 'secret-key-7Q2F'])->assertOk();

    expect($saved->json('sms'))->toMatchArray(['provider' => 'meticket', 'sender' => 'SUNRISE', 'key_hint' => '7Q2F', 'connected' => true])
        ->and($saved->getContent())->not->toContain('secret-key')
        ->and(DB::table('notification_routes')->where('channel', 'sms')->value('secret'))->not->toContain('secret-key')
        ->and(DB::table('audit_events')->where('action', 'notify.channel_saved')->get()->toJson())->not->toContain('secret-key');

    // Saving again without a key keeps the key.
    $this->actingAs($this->admin)->putJson('/api/v1/messages/channels/sms', ['provider' => 'meticket', 'sender' => 'SUNRISE2'])->assertOk()->assertJsonPath('sms.key_hint', '7Q2F');
});

it('sends the school\'s own words, refuses an unknown fact, and sends nothing when the notice is off', function (): void {
    $key = 'attendance.learner_absent';
    $this->actingAs($this->admin)->putJson("/api/v1/messages/notices/{$key}", ['short' => ':learner was absent. Ask for :teacher.', 'enabled' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['short']);
    $notice = $this->actingAs($this->admin)->putJson("/api/v1/messages/notices/{$key}", ['short' => 'Sunrise: :learner was not at school today (:day).', 'enabled' => true, 'contacts' => 'every'])
        ->assertOk()->json();

    expect($notice['own']['short'])->toBe('Sunrise: :learner was not at school today (:day).')
        ->and($notice['contacts'])->toBe('every');

    $this->actingAs($this->admin)->postJson("/api/v1/registers/class/{$this->class}", ['day' => MSG_TODAY, 'marks' => msgMarks($this->learners, ['present', 'absent', 'present'])])->assertOk();

    expect(array_column(LogSmsProvider::$sent, 'to'))->toBe(['+265991234567', '+265888410220'])
        ->and(LogSmsProvider::$sent[0]['text'])->toBe('Sunrise: Thoko Chisale was not at school today (Fri 13 Nov).');

    $this->actingAs($this->admin)->deleteJson("/api/v1/messages/notices/{$key}/wording")->assertOk()->assertJsonPath('own', null);
});

it('sends nothing while the school has the notice switched off', function (): void {
    $this->actingAs($this->admin)->putJson('/api/v1/messages/notices/attendance.learner_absent', ['enabled' => false])->assertOk()->assertJsonPath('enabled', false);
    $this->actingAs($this->admin)->postJson("/api/v1/registers/class/{$this->class}", ['day' => MSG_TODAY, 'marks' => msgMarks($this->learners, ['absent', 'absent', 'absent'])])->assertOk();

    expect(LogSmsProvider::$sent)->toBe([])
        ->and(DB::table('notification_deliveries')->where('topic_key', 'attendance.learner_absent')->pluck('reason')->unique()->all())->toBe(['switched_off']);
});

it('holds SMS for quiet hours and sends them in the morning', function (): void {
    $this->actingAs($this->admin)->putJson('/api/v1/messages/limits', ['quiet_from' => '19:00', 'quiet_to' => '06:00'])->assertOk();
    $this->travelTo('2026-11-13 18:30:00'); // 20:30 in Blantyre

    $this->actingAs($this->admin)->postJson("/api/v1/registers/class/{$this->class}", ['day' => MSG_TODAY, 'marks' => msgMarks($this->learners, ['present', 'absent', 'present'])])->assertOk();
    $row = DB::table('notification_deliveries')->where('topic_key', 'attendance.learner_absent')->first();

    expect($row->status)->toBe('queued')->and($row->reason)->toBe('quiet_hours')->and(LogSmsProvider::$sent)->toBe([]);

    $this->artisan('notify:sweep')->assertSuccessful();
    expect(LogSmsProvider::$sent)->toBe([]);

    $this->travelTo('2026-11-14 04:30:00'); // 06:30 in Blantyre
    $this->artisan('notify:sweep')->assertSuccessful();

    expect(LogSmsProvider::$sent)->toHaveCount(1)
        ->and(DB::table('notification_deliveries')->where('id', $row->id)->value('status'))->toBe('sent');
});

it('lists the messages of a day, and sends one again once when it was not delivered', function (): void {
    $token = msgRoute();
    $this->actingAs($this->admin)->postJson("/api/v1/registers/class/{$this->class}", ['day' => MSG_TODAY, 'marks' => msgMarks($this->learners, ['absent', 'absent', 'present'])])->assertOk();
    $failed = DB::table('notification_deliveries')->where('channel', 'sms')->first();
    $this->postJson("/api/v1/notify/reports/{$token}", ['ref' => $failed->provider_ref, 'stat' => 'EXPIRED'])->assertNoContent();

    $day = $this->actingAs($this->admin)->getJson('/api/v1/messages/sent?day='.MSG_TODAY)->assertOk()->json();

    expect($day['totals'])->toBe(['sent' => 2, 'delivered' => 0, 'waiting' => 0, 'attention' => 1])
        ->and(collect($day['messages'])->pluck('state', 'to')->all())->toEqual(['Ruth Banda' => 'accepted', 'Ruth Chisale' => 'not_delivered'])
        ->and(collect($day['messages'])->firstWhere('to', 'Ruth Chisale')['address'])->toBe('0991 234 567');

    $this->actingAs($this->admin)->postJson("/api/v1/messages/sent/{$failed->id}/again")->assertOk()->assertJsonPath('sent_again.state', 'sent');
    $this->actingAs($this->admin)->postJson("/api/v1/messages/sent/{$failed->id}/again")->assertUnprocessable();
    $this->actingAs($this->admin)->getJson("/api/v1/messages/sent/{$failed->id}")->assertOk()
        ->assertJsonPath('can_send_again', false)->assertJsonPath('steps.0.what', 'written');
});

it('refuses email notices for a contact without an email address', function (): void {
    $this->actingAs($this->admin)->postJson("/api/v1/learners/{$this->learners[1]}/contacts", ['name' => 'Grace Chisale', 'relationship' => 'aunt_uncle', 'phone' => '0999 517 003', 'notices_by' => 'email'])
        ->assertUnprocessable()->assertJsonValidationErrors(['contacts.0.notices_by']);
    $contact = DB::table('learner_contacts')->where('learner_id', $this->learners[0])->where('is_main', false)->value('id');
    $this->actingAs($this->admin)->postJson("/api/v1/learners/{$this->learners[0]}/contacts/{$contact}/notices", ['notices_by' => 'email'])->assertUnprocessable();
    $this->actingAs($this->admin)->postJson("/api/v1/learners/{$this->learners[0]}/contacts/{$contact}/notices", ['notices_by' => 'sms'])->assertOk();
});

it('counts SMS units and reads phone numbers as people write them', function (): void {
    expect(SmsLength::of(str_repeat('a', 160)))->toBe(['characters' => 160, 'units' => 1, 'unicode' => false])
        ->and(SmsLength::of(str_repeat('a', 161))['units'])->toBe(2)
        ->and(SmsLength::of(str_repeat('{', 80))['characters'])->toBe(160)
        ->and(SmsLength::of('Moni, mwana wanu sanabwere lero ŵ'))->toMatchArray(['unicode' => true, 'units' => 1])
        ->and(Phone::international('0991 234 567'))->toBe('+265991234567')
        ->and(Phone::international('991234567'))->toBe('+265991234567')
        ->and(Phone::international('265 888 410 220'))->toBe('+265888410220')
        ->and(Phone::international('+44 20 7946 0958'))->toBe('+442079460958')
        ->and(Phone::international('12345'))->toBeNull()
        ->and(Phone::forPeople('+265991234567'))->toBe('0991 234 567');
});

it('sends through the provider listed in configuration, and only writes to the log under the log driver', function (): void {
    $this->actingAs($this->admin)->putJson('/api/v1/messages/channels/sms', ['provider' => 'africastalking', 'sender' => 'SUNRISE', 'username' => '', 'key' => 'at-key-1234'])
        ->assertUnprocessable()->assertJsonValidationErrors(['username']);
    $this->actingAs($this->admin)->putJson('/api/v1/messages/channels/sms', ['provider' => 'africastalking', 'sender' => 'SUNRISE', 'username' => 'sunrise', 'key' => 'at-key-1234'])
        ->assertOk()->assertJsonPath('sms.test_mode', true)
        ->assertJsonPath('sms.providers.1', ['key' => 'africastalking', 'name' => "Africa's Talking", 'needs_username' => true]);

    expect(app(MessageSettings::class)->smsProvider())->toBeInstanceOf(LogSmsProvider::class);

    config(['acadly.sms.driver' => 'school']);
    expect(app(MessageSettings::class)->smsProvider())->toBeInstanceOf(AfricasTalkingProvider::class);

    config(['acadly.sms.providers' => ['meticket' => MeTicketProvider::class]]);
    expect(app(MessageSettings::class)->smsProvider())->toBeInstanceOf(NoSmsProvider::class);
    $this->actingAs($this->admin)->putJson('/api/v1/messages/channels/sms', ['provider' => 'africastalking', 'sender' => 'SUNRISE', 'username' => 'sunrise', 'key' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors(['provider']);
});
