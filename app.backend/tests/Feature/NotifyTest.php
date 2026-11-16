<?php

declare(strict_types=1);

use Acadly\Core\Academics\AcademicsCore;
use Acadly\Core\Enrolment\EnrolmentCore;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Access\Actions\AssignRole;
use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\AuditCatalogue;
use Acadly\Kernel\Blueprint\BlueprintCompiler;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Events\EventCatalogue;
use Acadly\Kernel\Events\Outbox;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\Licence\Licence;
use Acadly\Kernel\Module\Licence\Licences;
use Acadly\Kernel\Module\Licence\LicenceStatus;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Module\Tier;
use Acadly\Kernel\Notify\Actions\SetPreference;
use Acadly\Kernel\Notify\Actions\Subscribe;
use Acadly\Kernel\Notify\Actions\Unsubscribe;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Channels\DevelopmentSmsProvider;
use Acadly\Kernel\Notify\Channels\NoSmsProvider;
use Acadly\Kernel\Notify\Channels\SmsProvider;
use Acadly\Kernel\Notify\Jobs\ResolveNotice;
use Acadly\Kernel\Notify\KernelCategory;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\NoticeCatalogue;
use Acadly\Kernel\Notify\NoticeDispatcher;
use Acadly\Kernel\Notify\NoticeLinter;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Notify\OptOut;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;
use Acadly\Kernel\Notify\Words;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\AttendanceModule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Toy\Contracts\ErrandApproved;
use Tests\Fixtures\Toy\Contracts\ErrandEscalated;
use Tests\Fixtures\Toy\Contracts\ToyPermission;
use Tests\Fixtures\Toy\Journeys\Errand\OutcomeId;
use Tests\Fixtures\Toy\Journeys\Errand\StepId;
use Tests\Fixtures\Toy\ToyErrand;
use Tests\Fixtures\Toy\ToyModule;

uses(DatabaseTransactions::class);

/** @param  list<Module>  $extra */
function useToyBuild(array $extra = []): void
{
    app()->instance(ModuleRegistry::class, new ModuleRegistry([new InstitutionCore, new AcademicsCore, new EnrolmentCore, new AttendanceModule, new ToyModule, ...$extra]));

    foreach ([PermissionCatalogue::class, AuditCatalogue::class, EventCatalogue::class, NoticeCatalogue::class,
        Notifier::class, Audit::class, Outbox::class] as $service) {
        app()->forgetInstance($service);
    }

    app()->instance(Blueprints::class, new Blueprints(app(BlueprintCompiler::class)->compiled()));
    app()->forgetScopedInstances();
}

beforeEach(function (): void {
    useToyBuild();
    $this->artisan('acadly:sync')->assertSuccessful();
    DevelopmentSmsProvider::$sent = [];
});

/** @param  list<ToyPermission>  $permissions */
function staff(string $email, array $permissions = []): User
{
    $user = User::factory()->create(['email' => $email]);
    $role = Role::query()->create(['key' => 'notify_'.substr(Ids::next(), -12), 'name' => 'Errands']);
    $role->grant($permissions === [] ? ToyPermission::cases() : $permissions);

    RoleAssignment::query()->create([
        'user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => 'institution',
        'scope_id' => null, 'starts_at' => now()->subMinute(),
    ]);

    return $user;
}

function requestedErrand(User $requester, ?string $phone = '+265991234567'): ToyErrand
{
    return ToyErrand::query()->create([
        'title' => 'Collect the register books', 'state' => 'draft', 'journey_ref' => 'toy.errand@2026.1',
        'requested_by' => $requester->id, 'requester_phone' => $phone,
    ]);
}

/** @return array<string, string> channel => status, for one recipient */
function deliveriesTo(string $userId): array
{
    return DB::table('notification_deliveries')->where('recipient_user_id', $userId)->pluck('status', 'channel')->all();
}

describe('the catalogue', function (): void {
    it('holds the kernel notices and the module notices, and finds no problems', function (): void {
        $catalogue = app(NoticeCatalogue::class);

        expect($catalogue->has('notify.deliveries_waiting'))->toBeTrue()
            ->and($catalogue->has('toy.errand_approved'))->toBeTrue()
            ->and(app(NoticeLinter::class)->problems())->toBe([]);
    });

    it('finds undeclared tokens, missing short words and a silent notice without a fallback', function (): void {
        $bad = new class implements Topic
        {
            public static function notice(): Notice
            {
                return Notice::make('broken.notice')->named('Broken')->category(KernelCategory::System)
                    ->subject(':who did it')->line('Done.')
                    ->toDesk()
                    ->channels(default: [Channel::Sms], allowed: [Channel::Sms])
                    ->optOut(OptOut::Never);
            }

            public function subject(): SubjectRef
            {
                return SubjectRef::none();
            }

            public function scope(): ScopeRef
            {
                return ScopeRef::institution();
            }

            public function tokens(): array
            {
                return [];
            }

            public function dedupeKey(): string
            {
                return 'x';
            }

            public function toPayload(): array
            {
                return [];
            }

            public static function fromPayload(array $payload): static
            {
                return new self;
            }
        };

        $module = new class($bad::class) implements Module
        {
            public function __construct(private readonly string $topic) {}

            public function key(): string
            {
                return 'broken';
            }

            public function name(): string
            {
                return 'Broken';
            }

            public function tier(): Tier
            {
                return Tier::Core;
            }

            public function requires(): array
            {
                return [];
            }

            public function permissions(): array
            {
                return [];
            }

            public function auditActions(): array
            {
                return [];
            }

            public function events(): array
            {
                return [];
            }

            public function subscriptions(): array
            {
                return [];
            }

            public function journeys(): array
            {
                return [];
            }

            public function topics(): array
            {
                return [$this->topic];
            }

            public function documentKinds(): array
            {
                return [];
            }
        };

        useToyBuild([$module]);
        $problems = implode("\n", app(NoticeLinter::class)->problems());

        expect($problems)
            ->toContain('broken.notice: the words use :who')
            ->toContain('broken.notice: SMS is allowed, so the notice needs short words')
            ->toContain('broken.notice: a notice that cannot be silenced must be urgent');
    });

    it('is mirrored by sync, and the release is ready only when the hashes match', function (): void {
        expect(DB::table('notification_topics')->where('key', 'toy.errand_approved')->value('desk'))->toBeTrue();
        $this->getJson('/api/v1/ready')->assertOk()->assertExactJson(['status' => 'ready']);

        DB::table('system_sync_marks')->where('name', 'notify.catalogue')->update(['hash' => str_repeat('0', 64)]);

        $this->getJson('/api/v1/ready')->assertStatus(503)->assertExactJson(['status' => 'not_ready']);
    });

    it('fills tokens in one pass, and prints a dash for a missing one', function (): void {
        expect(Words::fill(':a and :b, :c', ['a' => 'x :b', 'b' => '', 'c' => null]))->toBe('x :b and —, —');
    });
});

describe('sending', function (): void {
    it('writes one request in the sender transaction, and resolves only after commit', function (): void {
        Queue::fake();
        $errand = requestedErrand(staff('requester@sunrise-academy.mw'));

        DB::transaction(function () use ($errand): void {
            expect(app(Notifier::class)->send(new ErrandApproved($errand->id)))->not->toBeNull()
                ->and(app(Notifier::class)->send(new ErrandApproved($errand->id)))->toBeNull();
        });

        expect(DB::table('notification_requests')->where('topic_key', 'toy.errand_approved')->count())->toBe(1)
            ->and(DB::table('notification_deliveries')->count())->toBe(0);
        Queue::assertPushed(ResolveNotice::class, 1);
    });

    it('sends nothing when the sender rolls back', function (): void {
        Queue::fake();
        $errand = requestedErrand(staff('requester@sunrise-academy.mw'));

        try {
            DB::transaction(function () use ($errand): void {
                app(Notifier::class)->send(new ErrandApproved($errand->id));

                throw new RuntimeException('The business action failed.');
            });
        } catch (RuntimeException) {
        }

        expect(DB::table('notification_requests')->where('subject_id', $errand->id)->count())->toBe(0);
        Queue::assertNotPushed(ResolveNotice::class);
    });

    it('tells the party and the desk on their channels, never the actor, and nobody twice', function (): void {
        $requester = staff('requester@sunrise-academy.mw', [ToyPermission::Submit, ToyPermission::Complete]);
        $desk = staff('desk@sunrise-academy.mw', [ToyPermission::Complete]);
        $decider = staff('decider@sunrise-academy.mw');
        app(Subscribe::class)->handle($desk, 'toy.errand_approved', ScopeRef::institution());
        app(Subscribe::class)->handle($decider, 'toy.errand_approved', ScopeRef::institution());
        app(Subscribe::class)->handle($requester, 'toy.errand_approved', ScopeRef::institution(), [Channel::InApp]);

        $errand = requestedErrand($requester);
        app(JourneyEngine::class)->perform($errand, $requester, StepId::Submit, OutcomeId::Submitted);
        app(JourneyEngine::class)->perform($errand, $decider, StepId::Decide, OutcomeId::Approved);

        expect(deliveriesTo($requester->id))->toEqual(['in_app' => 'delivered', 'email' => 'sent', 'sms' => 'sent'])
            ->and(deliveriesTo($desk->id))->toEqual(['in_app' => 'delivered', 'email' => 'sent'])
            ->and(deliveriesTo($decider->id))->toBe([])
            ->and(DB::table('notification_deliveries')->where('recipient_user_id', $requester->id)->value('audience'))->toBe('party');

        $row = DB::table('notification_deliveries')->where('recipient_user_id', $requester->id)->where('channel', 'email')->first();

        expect($row?->subject)->toBe('Collect the register books was approved')
            ->and($row?->body)->toContain('approved by '.$decider->name)
            ->and($row?->body)->toContain('Contact '.config('acadly.school_name'))
            ->and($row?->action_url)->toEndWith('/errands/'.$errand->id)
            ->and(DevelopmentSmsProvider::$sent)->toBe([['to' => '+265991234567', 'text' => 'Collect the register books was approved. Open Acadly for details.']])
            ->and(DB::table('notification_usage')->where('channel', 'sms')->sum('units'))->toEqual(1)
            ->and(DB::table('in_app_notifications')->where('user_id', $desk->id)->count())->toBe(1);
    });

    it('records that nobody was told', function (): void {
        $errand = requestedErrand(staff('requester@sunrise-academy.mw'));
        $errand->requested_by = null;
        $errand->save();

        app(Notifier::class)->send(new ErrandApproved($errand->id));

        expect(DB::table('notification_deliveries')->where('subject_id', $errand->id)->pluck('status')->all())->toBe(['unaddressed']);
    });

    it('leaves out addresses that cannot be reached', function (): void {
        $requester = staff('someone@example.com');
        $errand = requestedErrand($requester, '0991234567');

        app(Notifier::class)->send(new ErrandApproved($errand->id));

        expect(deliveriesTo($requester->id))->toEqual(['in_app' => 'delivered']);
    });

    it('honours a person who switched a channel off, unless the notice cannot be silenced', function (): void {
        $requester = staff('requester@sunrise-academy.mw');
        app(SetPreference::class)->handle($requester, 'toy.errand_approved', Channel::Sms, false);

        app(Notifier::class)->send(new ErrandApproved(requestedErrand($requester)->id));

        expect(deliveriesTo($requester->id))->not->toHaveKey('sms')
            ->and(fn () => app(SetPreference::class)->handle($requester, 'toy.errand_escalated', Channel::Sms, false))
            ->toThrow(ValidationException::class);
    });

    it('stops ordinary text messages at the licence allowance', function (): void {
        $licences = app(Licences::class);
        (fn () => $this->current = new Licence(LicenceStatus::Valid, 'T-1', 'Test', [], ['sms_per_month' => 0]))->call($licences);

        $requester = staff('requester@sunrise-academy.mw');
        app(Notifier::class)->send(new ErrandApproved(requestedErrand($requester)->id));

        expect(deliveriesTo($requester->id)['sms'] ?? null)->toBe('suppressed')
            ->and(DB::table('notification_deliveries')->where('recipient_user_id', $requester->id)->where('channel', 'sms')->value('reason'))->toBe('quota');
    });

    it('falls back to the next channel when an urgent notice fails', function (): void {
        app()->instance(SmsProvider::class, new NoSmsProvider);
        app()->forgetScopedInstances();

        $requester = staff('requester@sunrise-academy.mw');
        app(Notifier::class)->send(new ErrandEscalated(requestedErrand($requester)->id));
        expect(deliveriesTo($requester->id))->toEqual(['sms' => 'failed', 'email' => 'sent'])
            ->and(DB::table('notification_deliveries')->where('recipient_user_id', $requester->id)->where('channel', 'sms')->value('reason'))->toBe('no_provider');
    });

    it('marks a notice of a topic that left the build, and sends nothing', function (): void {
        $id = Ids::next();
        DB::table('notification_requests')->insert([
            'id' => $id, 'topic_key' => 'gone.notice', 'payload' => '{}', 'subject_type' => 'none', 'subject_id' => null,
            'scope_type' => 'institution', 'scope_id' => null, 'dedupe_key' => 'gone:'.$id, 'actor_user_id' => null,
            'people' => '[]', 'requested_at' => now(),
        ]);

        app(NoticeDispatcher::class)->resolve($id);
        app(NoticeDispatcher::class)->resolve($id);

        expect(DB::table('notification_deliveries')->where('request_id', $id)->pluck('reason')->all())->toBe(['not_in_build']);
    });
});

describe('the desk', function (): void {
    it('subscribes a person who gains a role, once, and never again after they leave', function (): void {
        $admin = staff('admin@sunrise-academy.mw');
        DB::table('access_role_assignments')->where('user_id', $admin->id)->delete();
        RoleAssignment::query()->create([
            'user_id' => $admin->id, 'role_id' => Role::query()->where('key', Role::ADMINISTRATOR)->value('id'),
            'scope_type' => 'institution', 'scope_id' => null, 'starts_at' => now()->subMinute(),
        ]);

        $completer = Role::query()->create(['key' => 'completer_'.substr(Ids::next(), -8), 'name' => 'Completer']);
        $completer->grant([ToyPermission::Complete]);
        $person = User::factory()->create();

        app(AssignRole::class)->handle($admin, $person, $completer, ScopeRef::institution());

        $active = fn (): int => DB::table('notification_subscriptions')->where('user_id', $person->id)
            ->where('topic_key', 'toy.errand_approved')->whereNull('ended_at')->count();

        expect($active())->toBe(1)
            ->and(DB::table('notification_subscriptions')->where('user_id', $person->id)->value('source'))->toBe('seeded');

        app(Unsubscribe::class)->handle($person, 'toy.errand_approved', ScopeRef::institution());
        $again = Role::query()->create(['key' => 'again_'.substr(Ids::next(), -8), 'name' => 'Again']);
        $again->grant([ToyPermission::Complete]);
        app(AssignRole::class)->handle($admin, $person, $again, ScopeRef::institution());

        expect($active())->toBe(0);
    });

    it('refuses a subscription to a person who cannot act on the notice', function (): void {
        $outsider = staff('outsider@sunrise-academy.mw', [ToyPermission::Submit]);

        expect(fn () => app(Subscribe::class)->handle($outsider, 'toy.errand_approved', ScopeRef::institution()))
            ->toThrow(ValidationException::class);
    });
});

describe('the sweep', function (): void {
    it('resolves a notice a stopped worker left, and reports deliveries waiting over an hour', function (): void {
        Queue::fake();
        $errand = requestedErrand(staff('requester@sunrise-academy.mw'));
        $id = app(Notifier::class)->send(new ErrandApproved($errand->id));

        $this->travel(2)->minutes();
        Queue::fake();
        $this->artisan('notify:sweep')->assertSuccessful();
        Queue::assertPushed(ResolveNotice::class, fn (ResolveNotice $job): bool => $job->requestId === $id);

        app(NoticeDispatcher::class)->resolve((string) $id);
        $this->travel(2)->hours();
        $this->artisan('notify:sweep')->assertSuccessful();

        expect(DB::table('notification_requests')->where('topic_key', 'notify.deliveries_waiting')->count())->toBe(1);
    });
});

describe('the bell', function (): void {
    it('lists the person own notices and marks one read, and no one else can', function (): void {
        $requester = staff('requester@sunrise-academy.mw');
        app(Notifier::class)->send(new ErrandApproved(requestedErrand($requester)->id));
        $notice = DB::table('in_app_notifications')->where('user_id', $requester->id)->first();

        $this->actingAs($requester)->getJson('/api/v1/me/notifications')
            ->assertOk()->assertJsonPath('unread', 1)->assertJsonPath('items.0.subject', 'Collect the register books was approved');

        $this->actingAs(staff('other@sunrise-academy.mw'))->postJson("/api/v1/me/notifications/{$notice?->id}/read")->assertNotFound();
        $this->actingAs($requester)->postJson("/api/v1/me/notifications/{$notice?->id}/read")->assertNoContent();
        $this->actingAs($requester)->getJson('/api/v1/me/notifications')->assertJsonPath('unread', 0);
    });
});
