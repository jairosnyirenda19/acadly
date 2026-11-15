<?php

declare(strict_types=1);

namespace Acadly\Kernel\Http;

use Acadly\Kernel\Notify\NoticeCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class ReadinessController
{
    /**
     * Readiness.
     *
     * Ready when the database has been brought into line with this release:
     * the notice catalogue it holds matches the one in the code (04 §9). It
     * discloses nothing else and requires no authentication.
     *
     * @response 200 array{status: 'ready'}
     * @response 503 array{status: 'not_ready'}
     */
    public function __invoke(NoticeCatalogue $catalogue): JsonResponse
    {
        $synced = DB::table('system_sync_marks')->where('name', 'notify.catalogue')->value('hash');

        return is_string($synced) && hash_equals($synced, $catalogue->hash())
            ? response()->json(['status' => 'ready'])
            : response()->json(['status' => 'not_ready'], 503);
    }
}
