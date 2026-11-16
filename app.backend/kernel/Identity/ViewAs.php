<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * View as: a development convenience (the approved design, owner ask
 * 2026-11-16, after e-office). An administrator steps into another account
 * without signing out, to see and do what that person sees and does. The
 * session is simply signed in as the other account, so every rule applies
 * to it exactly as it would; nothing is special-cased. The administrator's
 * id waits in the session for the way back.
 *
 * Four locks: it is off unless `acadly.development.view_as` turns it on; it
 * never works in production; only someone who may give roles for the whole
 * school may start it, and never into another such person; and it ends on
 * its own after `view_as_minutes`. Every audit line written meanwhile names
 * the administrator as the doer, acting for the other account.
 */
final class ViewAs
{
    public const string BY = 'acadly.view_as.by';

    public const string SINCE = 'acadly.view_as.since';

    public function __construct(
        private readonly Access $access,
        private readonly Clock $clock,
    ) {}

    /** Locks 1 and 2. */
    public function available(): bool
    {
        return config('acadly.development.view_as') === true && ! app()->isProduction();
    }

    /** Lock 3: who may step in, and who may be stepped into. */
    public function isAdministrator(User $user): bool
    {
        return $this->access->allows($user, AccessPermission::AssignRoles, ScopeRef::institution());
    }

    public function mayStart(User $user): bool
    {
        return $this->available() && $this->isAdministrator($user);
    }

    /** The administrator behind this session, while it views as someone. */
    public function by(Request $request): ?User
    {
        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(self::BY);

        return is_string($id) ? User::query()->find($id) : null;
    }

    /** Lock 4. */
    public function endsAt(Request $request): ?CarbonImmutable
    {
        $since = $request->hasSession() ? $request->session()->get(self::SINCE) : null;

        return is_string($since) ? CarbonImmutable::parse($since)->addMinutes(max(1, self::minutes())) : null;
    }

    public function due(Request $request): bool
    {
        $ends = $this->endsAt($request);

        return ! $this->available() || ($ends !== null && $ends <= $this->clock->now());
    }

    private static function minutes(): int
    {
        $minutes = config('acadly.development.view_as_minutes');

        return is_int($minutes) ? $minutes : 60;
    }
}
