<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Identity\ViewAs;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Starts and ends View as (see ViewAs for the four locks). Start and end are
 * audited as done by the administrator, whichever session asks.
 */
final class ViewAsActions
{
    public function __construct(
        private readonly ViewAs $viewAs,
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** Steps the administrator's session into another account. */
    public function start(Request $request, User $admin, string $targetId): User
    {
        if (! $this->viewAs->available()) {
            throw new NotFoundHttpException;
        }

        $this->access->check($admin, AccessPermission::AssignRoles, ScopeRef::institution());

        if (! $request->hasSession()) {
            throw ValidationException::withMessages(['session' => ['View as needs a signed-in session in the console.']]);
        }

        if ($this->viewAs->by($request) !== null) {
            throw ValidationException::withMessages(['user' => ['You are already viewing as someone. Leave first.']]);
        }

        $target = Ids::isValid($targetId) ? User::query()->find($targetId) : null;

        if ($target === null || $target->status === AccountStatus::Disabled) {
            throw new NotFoundHttpException;
        }

        if ($target->is($admin)) {
            throw ValidationException::withMessages(['user' => ['This is your own account.']]);
        }

        if ($this->viewAs->isAdministrator($target)) {
            throw ValidationException::withMessages(['user' => ['An administrator cannot be viewed as.']]);
        }

        $this->audit->record(KernelAudit::ViewAsStarted, subject: $target, actor: $this->audit->actorFor($admin), meta: ['as' => $target->id]);

        Auth::guard('web')->login($target);
        // After the sign-in: the guard writes its own keys to the session first.
        $request->session()->put(ViewAs::BY, $admin->id);
        $request->session()->put(ViewAs::SINCE, $this->clock->now()->toIso8601String());
        Auth::forgetGuards(); // no guard may keep the account it knew before
        Auth::guard('web')->setUser($target);
        $request->setUserResolver(fn (): User => $target);

        return $target;
    }

    /**
     * Steps back into the administrator's own account. Reachable by the viewed
     * session, which is not an administrator: the session key is the authority.
     */
    public function end(Request $request, string $why = 'left'): User
    {
        $admin = $this->viewAs->by($request);

        if ($admin === null) {
            throw ValidationException::withMessages(['session' => ['This session is not viewing as anyone.']]);
        }

        $as = Auth::guard('web')->user();

        if ($as instanceof User) {
            $this->audit->record(KernelAudit::ViewAsEnded, subject: $as, actor: $this->audit->actorFor($admin), meta: ['as' => $as->id, 'why' => $why]);
        }

        Auth::guard('web')->login($admin);
        $request->session()->forget([ViewAs::BY, ViewAs::SINCE]);
        Auth::forgetGuards();
        Auth::guard('web')->setUser($admin);
        $request->setUserResolver(fn (): User => $admin);

        return $admin;
    }
}
