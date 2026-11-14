<?php

declare(strict_types=1);

namespace Acadly\Kernel\Http;

use Illuminate\Http\JsonResponse;

final class HealthController
{
    /**
     * Service status.
     *
     * Reports that the service is reachable. It discloses nothing about the
     * release, the modules or the technology in use, and requires no
     * authentication.
     *
     * @response array{status: 'ok'}
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
