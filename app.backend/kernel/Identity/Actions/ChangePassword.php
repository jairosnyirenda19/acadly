<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A person changes their own password (D-089): the current one first. Other
 * devices that stayed signed in are signed out.
 */
final class ChangePassword
{
    public function __construct(
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function handle(User $who, string $current, string $new): void
    {
        if ($who->email === null) {
            throw ValidationException::withMessages(['password' => ['This account signs in with a code by text message.']]);
        }

        if (! Hash::check($current, $who->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is not correct.']]);
        }

        $who->forceFill(['password' => $new, 'password_changed_at' => $this->clock->now(), 'remember_token' => Str::random(60)])->save();
        $this->audit->record(KernelAudit::PasswordChanged, subject: $who, actor: $this->audit->actorFor($who));
    }
}
