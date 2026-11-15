<?php

declare(strict_types=1);

namespace Acadly\Kernel\Http;

use Illuminate\Http\JsonResponse;

final class SchoolController
{
    /**
     * The school this deployment serves.
     *
     * The sign-in screens show the school's name. It requires no
     * authentication and discloses nothing else.
     *
     * @response array{name: string}
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['name' => config()->string('acadly.school_name')]);
    }
}
