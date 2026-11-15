<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\InvitationMail;
use Acadly\Kernel\Identity\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTransactions::class);

const SETUP_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

beforeEach(function (): void {
    Storage::fake('documents');
    $this->artisan('acadly:sync')->assertSuccessful();
});

function setupAdministrator(): User
{
    $user = User::factory()->create(['email' => 'g.phiri@sunriseacademy.mw', 'name' => 'Grace Phiri']);
    RoleAssignment::query()->create([
        'user_id' => $user->id, 'role_id' => Role::query()->where('key', Role::ADMINISTRATOR)->value('id'),
        'scope_type' => 'institution', 'scope_id' => null, 'starts_at' => now()->subMinute(),
    ]);

    return $user;
}

/** @return array<string, mixed> */
function schoolDetails(array $overrides = []): array
{
    return [
        'name' => 'Sunrise Academy', 'short_name' => 'Sunrise', 'code' => 'SRA',
        'email' => 'office@sunriseacademy.mw', 'phone' => '+265 1 754 210', 'website' => 'www.sunriseacademy.mw',
        'country' => 'MW', 'district' => 'Lilongwe',
        'physical_address' => 'Plot 47/3/112, Area 47, Lilongwe', 'postal_address' => 'P.O. Box 31502, Lilongwe 3',
        ...$overrides,
    ];
}

function threeTerms(): array
{
    return [
        'name' => '2026/27', 'starts_on' => '2026-09-07', 'ends_on' => '2027-07-23',
        'terms' => [
            ['name' => 'Term 1', 'starts_on' => '2026-09-07', 'ends_on' => '2026-12-11'],
            ['name' => 'Term 2', 'starts_on' => '2027-01-04', 'ends_on' => '2027-04-02'],
            ['name' => 'Term 3', 'starts_on' => '2027-04-26', 'ends_on' => '2027-07-23'],
        ],
    ];
}

/** Runs the required steps: details, two levels at the main campus plus Blantyre, the year, and classes everywhere. */
function completeSetup($test, User $admin): array
{
    $test->actingAs($admin)->putJson('/api/v1/institution', schoolDetails())->assertOk();
    $state = $test->actingAs($admin)->putJson('/api/v1/institution/levels', ['levels' => [['profile' => 'PRIMARY'], ['profile' => 'SECONDARY']]])->assertOk()->json();
    $test->actingAs($admin)->putJson('/api/v1/academics/year', threeTerms())->assertOk();
    $academics = $test->actingAs($admin)->getJson('/api/v1/academics')->json();

    foreach ($academics['offerings'] as $offering) {
        $test->actingAs($admin)->putJson('/api/v1/academics/classes', [
            'division_id' => $offering['division_id'], 'campus_id' => $offering['campus_id'], 'streams' => [],
        ])->assertOk();
    }

    return $state;
}

describe('the school', function (): void {
    it('offers the level profiles and keeps the school details out of reach of other staff', function (): void {
        $this->actingAs(setupAdministrator())->getJson('/api/v1/institution')
            ->assertOk()
            ->assertJsonPath('profiles.0.key', 'ECD')
            ->assertJsonPath('profiles.1.words', 'Pupil · Standard · Term')
            ->assertJsonPath('profiles.2.title', 'Secondary')
            ->assertJsonPath('school.details_complete', false);

        $this->actingAs(User::factory()->create())->getJson('/api/v1/institution')->assertForbidden();
    });

    it('saves the details, refuses wrong values, and names the school on the sign-in page', function (): void {
        $admin = setupAdministrator();

        $this->actingAs($admin)->putJson('/api/v1/institution', schoolDetails(['code' => 'sra', 'district' => 'Paris']))
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->actingAs($admin)->putJson('/api/v1/institution', schoolDetails(['district' => 'Paris']))
            ->assertUnprocessable()->assertJsonValidationErrors(['district']);

        $this->actingAs($admin)->putJson('/api/v1/institution', schoolDetails(['name' => 'Sunrise Academy Lilongwe']))
            ->assertOk()->assertJsonPath('school.details_complete', true);

        $this->getJson('/api/v1/school')->assertExactJson(['name' => 'Sunrise Academy Lilongwe', 'has_logo' => false]);
        expect(DB::table('audit_events')->where('action', 'institution.details_saved')->exists())->toBeTrue();
    });

    it('keeps the logo as a document of the school and shows it on the sign-in page', function (): void {
        $admin = setupAdministrator();
        $png = UploadedFile::fake()->createWithContent('crest.png', (string) base64_decode(SETUP_PNG));

        $this->actingAs($admin)->post('/api/v1/institution/logo', ['file' => $png], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('school.has_logo', true);
        $this->actingAs($admin)->post('/api/v1/institution/logo', ['file' => $png], ['Accept' => 'application/json'])->assertOk();

        auth()->forgetGuards();
        $logo = $this->get('/api/v1/school/logo');

        $logo->assertOk()->assertHeader('Content-Type', 'image/png');
        expect($logo->streamedContent())->toBe((string) base64_decode(SETUP_PNG))
            ->and(DB::table('documents')->where('kind', 'institution.logo')->count())->toBe(1)
            ->and(DB::table('document_files')->count())->toBe(2);
    });
});

describe('levels and campuses', function (): void {
    it('pins each level to its profile, and makes one campus where each is taught', function (): void {
        $admin = setupAdministrator();

        $state = $this->actingAs($admin)->putJson('/api/v1/institution/levels', ['levels' => [['profile' => 'PRIMARY'], ['profile' => 'SECONDARY', 'name' => 'Secondary section']]])
            ->assertOk()->json();

        expect(array_column($state['divisions'], 'name'))->toBe(['Primary', 'Secondary section'])
            ->and($state['campuses'])->toHaveCount(1)
            ->and($state['offerings'])->toHaveCount(2)
            ->and(DB::table('institution_divisions')->pluck('profile_ref')->sort()->values()->all())->toBe(['PRIMARY@2026.1', 'SECONDARY@2026.1']);

        $context = $this->actingAs($admin)->getJson('/api/v1/me/context')->assertOk();
        $context->assertJsonPath('setup_required', true)->assertJsonPath('divisions.1.vocabulary.learner.one', 'Student');
    });

    it('refuses a level that is not available, and adds a campus where one level is taught', function (): void {
        $admin = setupAdministrator();

        $this->actingAs($admin)->putJson('/api/v1/institution/levels', ['levels' => [['profile' => 'SCHOOL_BASE']]])->assertUnprocessable();

        $state = $this->actingAs($admin)->putJson('/api/v1/institution/levels', ['levels' => [['profile' => 'PRIMARY'], ['profile' => 'SECONDARY']]])->json();
        $state = $this->actingAs($admin)->postJson('/api/v1/institution/campuses', ['name' => 'Blantyre Campus', 'place' => 'Blantyre'])->assertOk()->json();
        $blantyre = collect($state['campuses'])->firstWhere('name', 'Blantyre Campus');
        $secondary = collect($state['divisions'])->firstWhere('profile', 'SECONDARY');

        $this->actingAs($admin)->postJson('/api/v1/institution/campuses', ['name' => 'blantyre campus', 'place' => 'Blantyre'])->assertUnprocessable();

        $state = $this->actingAs($admin)->putJson('/api/v1/institution/offerings', ['division_id' => $secondary['id'], 'campus_id' => $blantyre['id'], 'taught' => true])
            ->assertOk()->json();

        expect($state['offerings'])->toHaveCount(3);
    });
});

describe('the academic year and classes', function (): void {
    it('sets the year with as many terms as the levels use, in order and inside the year', function (): void {
        $admin = setupAdministrator();
        $this->actingAs($admin)->putJson('/api/v1/institution/levels', ['levels' => [['profile' => 'PRIMARY']]]);

        $two = threeTerms();
        array_pop($two['terms']);
        $this->actingAs($admin)->putJson('/api/v1/academics/year', $two)->assertUnprocessable()->assertJsonValidationErrors(['terms']);

        $overlap = threeTerms();
        $overlap['terms'][1]['starts_on'] = '2026-12-01';
        $this->actingAs($admin)->putJson('/api/v1/academics/year', $overlap)->assertUnprocessable();

        $this->actingAs($admin)->putJson('/api/v1/academics/year', threeTerms())
            ->assertOk()->assertJsonPath('year.name', '2026/27')->assertJsonPath('year.periods.2.ends_on', '2027-07-23')->assertJsonPath('period_word', 'Term');
    });

    it('makes the classes of a level at a campus from its ladder and the streams the school names', function (): void {
        $admin = setupAdministrator();
        $state = $this->actingAs($admin)->putJson('/api/v1/institution/levels', ['levels' => [['profile' => 'SECONDARY']]])->json();
        $this->actingAs($admin)->putJson('/api/v1/academics/year', threeTerms());
        $offering = ['division_id' => $state['divisions'][0]['id'], 'campus_id' => $state['campuses'][0]['id']];

        $this->actingAs($admin)->putJson('/api/v1/academics/classes', [...$offering, 'streams' => ['F9' => ['East']]])->assertUnprocessable();

        $academics = $this->actingAs($admin)->putJson('/api/v1/academics/classes', [...$offering, 'streams' => ['F1' => ['East', 'West'], 'F2' => ['East', 'West']]])
            ->assertOk()->json();

        expect($academics['classes'])->toBe(6)
            ->and($academics['offerings'][0]['levels'][0]['streams'])->toBe(['East', 'West'])
            ->and($academics['offerings'][0]['level_word'])->toBe('Form')
            ->and(DB::table('academic_classes')->whereNull('ended_at')->pluck('name')->sort()->values()->all())
            ->toBe(['Form 1 East', 'Form 1 West', 'Form 2 East', 'Form 2 West', 'Form 3', 'Form 4']);

        $again = $this->actingAs($admin)->putJson('/api/v1/academics/classes', [...$offering, 'streams' => ['F1' => ['East']]])->json();

        expect($again['classes'])->toBe(4)
            ->and(DB::table('academic_classes')->whereNotNull('ended_at')->count())->toBe(3);
    });
});

describe('staff and opening', function (): void {
    it('invites staff, sends the invitations, and lets an invited person set a first password', function (): void {
        Notification::fake();
        $admin = setupAdministrator();
        completeSetup($this, $admin);

        $this->actingAs($admin)->postJson('/api/v1/setup/staff', ['name' => 'Thokozani Gondwe', 'email' => 't.gondwe@gmail', 'role' => 'class_teacher', 'place_type' => 'institution'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $state = $this->actingAs($admin)->postJson('/api/v1/setup/staff', ['name' => 'Mercy Banda', 'email' => 'm.banda@sunriseacademy.mw', 'role' => 'class_teacher', 'place_type' => 'institution'])
            ->assertOk()->json();
        $mercy = collect($state['staff'])->firstWhere('email', 'm.banda@sunriseacademy.mw');

        expect($mercy['state'])->toBe('ready')->and($mercy['role'])->toBe('Class teacher')->and($state['waiting'])->toBe(1);

        $state = $this->actingAs($admin)->postJson('/api/v1/setup/staff/send')->assertOk()->json();
        expect(collect($state['staff'])->firstWhere('email', 'm.banda@sunriseacademy.mw')['state'])->toBe('sent');

        $user = User::query()->where('email', 'm.banda@sunriseacademy.mw')->firstOrFail();
        $token = null;
        Notification::assertSentTo($user, InvitationMail::class, function (InvitationMail $mail) use ($user, &$token): bool {
            preg_match('/token=([^&]+)/', (string) $mail->toMail($user)->actionUrl, $match);
            $token = urldecode($match[1] ?? '');

            return true;
        });

        $this->postJson('/api/v1/auth/sign-in', ['email' => 'm.banda@sunriseacademy.mw', 'password' => 'a password nobody knows'], ['Origin' => config('acadly.frontend_url')])->assertUnprocessable();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'm.banda@sunriseacademy.mw', 'invitation' => true,
            'password' => 'register-books-2026', 'password_confirmation' => 'register-books-2026',
        ])->assertNoContent();

        expect($user->fresh()?->status)->toBe(AccountStatus::Active)
            ->and(DB::table('audit_events')->where('action', 'identity.invitation_accepted')->exists())->toBeTrue();
    });

    it('refuses to open while a required step is not done, then opens and fixes the school code', function (): void {
        $admin = setupAdministrator();

        $this->actingAs($admin)->postJson('/api/v1/setup/open')->assertUnprocessable()->assertJsonValidationErrors(['setup']);
        expect($this->actingAs($admin)->getJson('/api/v1/setup')->json('blockers'))->toBe(['school', 'levels', 'year', 'classes']);

        completeSetup($this, $admin);

        $this->actingAs($admin)->postJson('/api/v1/setup/open')->assertOk()->assertJsonPath('open', true);
        $this->actingAs($admin)->getJson('/api/v1/me/context')->assertJsonPath('setup_required', false)->assertJsonPath('school.open', true);

        $this->actingAs($admin)->putJson('/api/v1/institution', schoolDetails(['code' => 'SUN']))->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->actingAs($admin)->putJson('/api/v1/institution/levels', ['levels' => [['profile' => 'PRIMARY']]])->assertUnprocessable();

        expect(DB::table('audit_events')->where('action', 'institution.opened')->exists())->toBeTrue();
    });
});
