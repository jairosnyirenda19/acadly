<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Http;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\User;
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
    public function __invoke(Request $request, Audit $audit): Response
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User) {
            $audit->record(KernelAudit::SignedOut, subject: $user);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
