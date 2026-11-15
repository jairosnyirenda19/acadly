<?php

declare(strict_types=1);

use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Module\Tier;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\AttendanceModule;

it('reports service status and nothing else', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('names the school without authentication', function (): void {
    $this->getJson('/api/v1/school')
        ->assertOk()
        ->assertExactJson(['name' => 'Sunrise Academy', 'has_logo' => false]);
});

it('does not disclose the technology in use', function (): void {
    $this->get('/api/v1/health')->assertHeaderMissing('X-Powered-By');

    $root = $this->get('/');
    $body = (string) $root->getContent();

    $root->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson([
            'type' => 'about:blank',
            'title' => 'The resource was not found.',
            'status' => 404,
            'code' => 'not_found',
        ]);

    foreach (['laravel', 'symfony', 'exception', 'trace', '.php', 'vendor'] as $word) {
        expect(strtolower($body))->not->toContain($word);
    }
});

it('does not disclose internals when debug mode is on', function (): void {
    config(['app.debug' => true]);

    $body = strtolower((string) $this->get('/no-such-path')->getContent());

    expect($body)->not->toContain('exception')->not->toContain('trace')->not->toContain('.php');
});

it('registers the modules listed in the configuration', function (): void {
    $keys = array_map(fn (Module $module): string => $module->key(), app(ModuleRegistry::class)->all());

    expect($keys)->toBe(['institution', 'academics', 'enrolment', 'setup', 'attendance']);
});

it('passes the structural lint', function (): void {
    $this->artisan('acadly:lint')->assertSuccessful();
});

it('makes version 7 identifiers that sort by time', function (): void {
    $first = Ids::next();
    usleep(2000);
    $second = Ids::next();

    expect(Ids::isValid($first))->toBeTrue()
        ->and($first[14])->toBe('7')
        ->and(strcmp($first, $second))->toBeLessThan(0);
});

it('refuses a core domain that requires an optional module', function (): void {
    $core = new class implements Module
    {
        public function key(): string
        {
            return 'broken_core';
        }

        public function name(): string
        {

            return 'Test module';

        }

        public function tier(): Tier
        {
            return Tier::Core;
        }

        public function requires(): array
        {
            return [AttendanceModule::class];
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
            return [];
        }

        public function documentKinds(): array
        {
            return [];
        }
    };

    new ModuleRegistry([new InstitutionCore, new AttendanceModule, $core]);
})->throws(LogicException::class, 'cannot require the optional module');

it('refuses a module that requires one that is not in the build', function (): void {
    new ModuleRegistry([new AttendanceModule]);
})->throws(LogicException::class, 'is not registered');

it('refuses two modules with one key', function (): void {
    new ModuleRegistry([new InstitutionCore, new InstitutionCore]);
})->throws(LogicException::class, 'Two modules use the key');
