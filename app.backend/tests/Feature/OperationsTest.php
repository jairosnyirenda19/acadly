<?php

declare(strict_types=1);

use Acadly\Kernel\Operations\DatabaseRole;
use Acadly\Kernel\Operations\Heartbeats;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Running a school's server (D-112): the readiness check fails while the
 * scheduler or the queue worker has stopped, or while the application could
 * change the database; the preflight check refuses a server that is not set
 * up safely.
 */

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-25 08:00:00');
    $this->artisan('acadly:sync')->assertSuccessful();
});

it('runs the application as a role that cannot change the database', function (): void {
    expect(DatabaseRole::safe())->toBeTrue();
});

it('is ready on a server only while the scheduler and the queue worker are running', function (): void {
    config(['acadly.operations.watch_workers' => true]);
    DB::table('system_heartbeats')->delete();
    $this->getJson('/api/v1/ready')->assertStatus(503)->assertExactJson(['status' => 'not_ready']);

    // The scheduler beats, and the queue worker answers the job it is sent.
    Artisan::call('system:heartbeat');
    expect(app(Heartbeats::class)->alive())->toBe(['scheduler' => true, 'queue' => true]);
    $this->getJson('/api/v1/ready')->assertOk()->assertExactJson(['status' => 'ready']);

    // The scheduler stops: four minutes later the server is no longer ready.
    $this->travel(4)->minutes();
    $this->getJson('/api/v1/ready')->assertStatus(503);
    expect(app(Heartbeats::class)->alive())->toBe(['scheduler' => false, 'queue' => true]);
});

it('does not watch the workers on a developer machine or in the tests', function (): void {
    DB::table('system_heartbeats')->delete();
    expect(Heartbeats::watched())->toBeFalse();
    $this->getJson('/api/v1/ready')->assertOk();
});

it('refuses a server that is not set up safely, and names what to set without printing a secret', function (): void {
    $code = Artisan::call('acadly:preflight');
    $out = Artisan::output();

    expect($code)->toBe(1)
        ->and($out)->toContain('Set APP_ENV=production.')
        ->and($out)->toContain('Set SESSION_SECURE_COOKIE=true.')
        ->and($out)->toContain('not ready for a school')
        ->and($out)->not->toContain((string) config('app.key'));
});
