<?php

declare(strict_types=1);

namespace Acadly\Kernel\Http;

use Acadly\Kernel\School\SchoolProfile;
use Illuminate\Http\JsonResponse;

final class SchoolController
{
    /**
     * The school this deployment serves.
     *
     * The sign-in screens show the school's name and, when there is one, its
     * logo. It requires no authentication and discloses nothing else.
     *
     * @response array{name: string, has_logo: bool}
     */
    public function __invoke(SchoolProfile $school): JsonResponse
    {
        return response()->json(['name' => $school->name(), 'has_logo' => $school->hasLogo()]);
    }
}
