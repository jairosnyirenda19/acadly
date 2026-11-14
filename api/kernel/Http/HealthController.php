<?php

declare(strict_types=1);

namespace Acadly\Kernel\Http;

use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\ModuleRegistry;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    /**
     * Health of the API.
     *
     * Says that the API answers, which release it is, and which modules are in
     * this build. It reads no tenant data and needs no sign-in.
     *
     * @response array{status: string, service: string, version: string, modules: list<array{key: string, tier: 'core'|'optional'}>}
     */
    public function __invoke(ModuleRegistry $modules): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'acadly-api',
            'version' => config()->string('acadly.version'),
            'modules' => array_map(
                fn (Module $module): array => [
                    'key' => $module->key(),
                    'tier' => $module->tier()->value,
                ],
                $modules->all(),
            ),
        ]);
    }
}
