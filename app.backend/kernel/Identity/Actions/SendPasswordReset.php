<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Sends a member of staff the link that sets a new password, on their behalf.
 * The link goes to their own email address; nobody else sees it.
 */
final class SendPasswordReset
{
    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
    ) {}

    public function handle(User $by, User $person): void
    {
        // Refused unless every role the person holds is inside the giver's reach;
        // the whole-school check records the refusal.
        if (! $this->access->reaches($by, AccessPermission::AssignRoles, $person)) {
            $this->access->check($by, AccessPermission::AssignRoles);

            throw new AuthorizationException('Access is refused.');
        }

        if ($person->status !== AccountStatus::Active) {
            throw ValidationException::withMessages(['user' => ['Only an active account can be sent a password reset link.']]);
        }

        Password::broker()->sendResetLink(['email' => $person->email, 'status' => AccountStatus::Active->value]);

        $this->audit->record(KernelAudit::ResetSent, subject: $person, actor: $this->audit->actorFor($by));
    }
}
