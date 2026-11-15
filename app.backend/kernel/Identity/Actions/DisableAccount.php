<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Audit\Watch;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Disables an account. Every role it holds stops at once, because access is
 * refused to an account that is not active; the assignments are kept, so the
 * history stays whole. The account is signed out everywhere.
 *
 * Only someone who can give roles at every place the person holds one may do
 * it, and never to the last account that can give roles for the whole school.
 */
final class DisableAccount
{
    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
    ) {}

    public function handle(User $by, User $person, string $reason): void
    {
        // Refused unless every role the person holds is inside the giver's reach;
        // the whole-school check records the refusal.
        if (! $this->access->reaches($by, AccessPermission::AssignRoles, $person)) {
            $this->access->check($by, AccessPermission::AssignRoles);

            throw new AuthorizationException('Access is refused.');
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        if ($person->status === AccountStatus::Disabled) {
            throw ValidationException::withMessages(['user' => ['The account is already disabled.']]);
        }

        if ($this->access->holders(AccessPermission::AssignRoles) > 0
            && $this->access->holders(AccessPermission::AssignRoles, exceptUser: $person->id) === 0) {
            throw ValidationException::withMessages(['user' => ['This is the last account that can give roles for the whole school. Give that permission to another account first.']]);
        }

        DB::transaction(function () use ($by, $person, $reason): void {
            $watch = Watch::of($person);
            $person->forceFill(['status' => AccountStatus::Disabled, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $person->id)->delete();

            $this->audit->record(KernelAudit::AccountDisabled, subject: $person, changes: $watch->diff(), reason: trim($reason),
                actor: $this->audit->actorFor($by));
        });

        $this->access->forget($person);
    }
}
