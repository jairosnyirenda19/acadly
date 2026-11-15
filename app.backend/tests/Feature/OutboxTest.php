<?php

declare(strict_types=1);

use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Actions\AssignRole;
use Acadly\Kernel\Access\Events\RoleAssigned;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Events\EventCatalogue;
use Acadly\Kernel\Events\Jobs\DeliverEvent;
use Acadly\Kernel\Events\Jobs\PublishOutbox;
use Acadly\Kernel\Events\Outbox;
use Acadly\Kernel\Events\OutboxPublisher;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Module\Tier;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\AttendanceModule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\ListenerModule;
use Tests\Fixtures\RecordingConsumer;
use Tests\Fixtures\UnlistedEvent;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->artisan('acadly:sync')->assertSuccessful();

    RecordingConsumer::$seen = [];
    RecordingConsumer::$fail = false;

    app()->instance(EventCatalogue::class, new EventCatalogue(
        new ModuleRegistry([new InstitutionCore, new AttendanceModule, new ListenerModule]),
    ));
    app()->forgetInstance(Outbox::class);
    app()->forgetInstance(OutboxPublisher::class);
});

function sampleEvent(): RoleAssigned
{
    return new RoleAssigned(Ids::next(), Ids::next(), 'office', 'institution', null, false, null);
}

/** Gives a role through the real Action, so the event comes from real work. */
function giveRole(): RoleAssignment
{
    $admin = User::factory()->create();
    $administrator = Role::query()->where('key', Role::ADMINISTRATOR)->firstOrFail();
    RoleAssignment::query()->create([
        'user_id' => $admin->id, 'role_id' => $administrator->id, 'scope_type' => 'institution',
        'scope_id' => null, 'starts_at' => now()->subMinute(),
    ]);

    $role = Role::query()->create(['key' => 'office_'.substr(Ids::next(), -8), 'name' => 'Office']);
    $role->grant([AccessPermission::AssignRoles]);

    return app(AssignRole::class)->handle($admin, User::factory()->create(), $role, ScopeRef::institution());
}

it('writes the event in the transaction of the change', function (): void {
    Queue::fake();

    $assignment = giveRole();
    $row = DB::table('outbox_events')->where('name', 'access.role_assigned')->orderByDesc('id')->first();

    expect($row)->not->toBeNull()
        ->and(json_decode((string) $row?->payload, true)['assignment_id'])->toBe($assignment->id);

    Queue::assertPushed(PublishOutbox::class);
});

it('leaves no event and starts no publish when the change rolls back', function (): void {
    Queue::fake();
    $before = DB::table('outbox_events')->count();

    try {
        DB::transaction(function (): void {
            app(Outbox::class)->record(sampleEvent());

            throw new RuntimeException('The change failed.');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(DB::table('outbox_events')->count())->toBe($before);
    Queue::assertNotPushed(PublishOutbox::class);
});

it('refuses an event written outside a transaction', function (): void {
    // The test wraps everything in a transaction; leave it for this one call.
    DB::commit();

    try {
        app(Outbox::class)->record(sampleEvent());
    } finally {
        DB::beginTransaction();
    }
})->throws(LogicException::class, 'must be written in the transaction');

it('publishes once and delivers to every consumer', function (): void {
    Queue::fake();

    DB::transaction(fn (): string => app(Outbox::class)->record(sampleEvent()));

    expect(app(OutboxPublisher::class)->publish())->toBeGreaterThanOrEqual(1)
        ->and(app(OutboxPublisher::class)->publish())->toBe(0);

    Queue::assertPushed(DeliverEvent::class, fn (DeliverEvent $job): bool => $job->consumer === 'listener.recorder');
});

it('gives an event to a consumer at most once', function (): void {
    Queue::fake();
    $id = DB::transaction(fn (): string => app(Outbox::class)->record(sampleEvent()));
    $job = new DeliverEvent($id, 'listener.recorder');

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    expect(RecordingConsumer::$seen)->toHaveCount(1)
        ->and(RecordingConsumer::$seen[0])->toBeInstanceOf(RoleAssigned::class)
        ->and(DB::table('consumed_events')->where('event_id', $id)->count())->toBe(1);
});

it('keeps no delivery record when the consumer fails, so it is retried', function (): void {
    Queue::fake();
    $id = DB::transaction(fn (): string => app(Outbox::class)->record(sampleEvent()));
    RecordingConsumer::$fail = true;

    try {
        app()->call([new DeliverEvent($id, 'listener.recorder'), 'handle']);
    } catch (RuntimeException) {
        // expected
    }

    expect(DB::table('consumed_events')->where('event_id', $id)->count())->toBe(0);

    RecordingConsumer::$fail = false;
    app()->call([new DeliverEvent($id, 'listener.recorder'), 'handle']);

    expect(RecordingConsumer::$seen)->toHaveCount(1);
});

it('runs end to end on the queue after commit', function (): void {
    giveRole();

    expect(RecordingConsumer::$seen)->toHaveCount(1)
        ->and(RecordingConsumer::$seen[0])->toBeInstanceOf(RoleAssigned::class);
});

it('refuses to change a delivery record', function (): void {
    Queue::fake();
    $id = DB::transaction(fn (): string => app(Outbox::class)->record(sampleEvent()));
    app()->call([new DeliverEvent($id, 'listener.recorder'), 'handle']);

    DB::table('consumed_events')->where('event_id', $id)->delete();
})->throws(QueryException::class);

it('refuses a subscription to an event that is not in the build', function (): void {
    $module = new class implements Module
    {
        public function key(): string
        {
            return 'stray';
        }

        public function tier(): Tier
        {
            return Tier::Optional;
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
            return [UnlistedEvent::class => [RecordingConsumer::class]];
        }
    };

    new EventCatalogue(new ModuleRegistry([new InstitutionCore, $module]));
})->throws(LogicException::class, 'not an event in this build');
