<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class SignOutController
{
    /**
     * Sign out.
     *
     * Ends the session.
     */
    public function __invoke(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
