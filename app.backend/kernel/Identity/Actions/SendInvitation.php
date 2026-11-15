<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\InvitationMail;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Sends an invited person the link that sets their first password, valid for
 * 7 days. Sending again replaces the earlier link. The link is mailed
 * straight to the person and never stored in a delivery record.
 */
final class SendInvitation
{
    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
        private readonly SchoolProfile $school,
    ) {}

    public function handle(User $by, User $to): void
    {
        $this->access->check($by, AccessPermission::AssignRoles);

        if ($to->status !== AccountStatus::Invited) {
            throw ValidationException::withMessages(['user' => ['The account has already been set up.']]);
        }

        $school = $this->school->name();

        Password::broker('invitations')->sendResetLink(
            ['email' => $to->email, 'status' => AccountStatus::Invited->value],
            function (CanResetPassword $user, string $token) use ($school): void {
                if ($user instanceof User) {
                    $user->notify(new InvitationMail($token, $school));
                }
            },
        );

        $to->forceFill(['invitation_sent_at' => $this->clock->now()])->save();

        $this->audit->record(KernelAudit::InvitationSent, subject: $to, actor: $this->audit->actorFor($by));
    }
}
