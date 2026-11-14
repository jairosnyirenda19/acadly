<?php

declare(strict_types=1);

use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Module\Tier;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Attendance\AttendanceModule;

it('answers on /api/v1/health with the modules in the build', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('service', 'acadly-api')
        ->assertJsonFragment(['key' => 'institution', 'tier' => 'core'])
        ->assertJsonFragment(['key' => 'attendance', 'tier' => 'optional']);
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

        public function tier(): Tier
        {
            return Tier::Core;
        }

        public function requires(): array
        {
            return [AttendanceModule::class];
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
