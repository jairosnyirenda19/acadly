<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

/** The kernel's own audit actions: accounts, access and the trail itself. */
enum KernelAudit: string implements AuditAction
{
    case SignedIn = 'identity.signed_in';
    case SignInFailed = 'identity.sign_in_failed';
    case SignInPaused = 'identity.sign_in_paused';
    case SignedOut = 'identity.signed_out';
    case ResetRequested = 'identity.reset_requested';
    case PasswordReset = 'identity.password_reset';
    case FirstAdministrator = 'access.first_administrator';
    case RoleAssigned = 'access.role_assigned';
    case RoleEnded = 'access.role_ended';
    case Refused = 'access.refused';
    case ModuleSwitched = 'modules.switched';
    case LicenceInstalled = 'modules.licence_installed';
    case StepPerformed = 'journeys.step_performed';
    case Sealed = 'audit.sealed';
    case ChainBroken = 'audit.chain_broken';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::SignedIn => 'Signed in.',
            self::SignInFailed => 'Attempted to sign in with an incorrect email address or password.',
            self::SignInPaused => 'Attempted to sign in while sign-in was paused after repeated failures.',
            self::SignedOut => 'Signed out.',
            self::ResetRequested => 'Requested a password reset link.',
            self::PasswordReset => 'Set a new password with a reset link.',
            self::FirstAdministrator => 'Created the first administrator of the deployment.',
            self::RoleAssigned => 'Gave a role.',
            self::RoleEnded => 'Ended a role.',
            self::Refused => 'Attempted an action without the required permission.',
            self::ModuleSwitched => 'Switched a module on or off.',
            self::LicenceInstalled => 'Installed a licence.',
            self::StepPerformed => 'Performed a step of a process on a record.',
            self::Sealed => 'Sealed the audit trail.',
            self::ChainBroken => 'Found that the audit trail does not verify.',
        };
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::RoleEnded, self::ModuleSwitched], true);
    }
}
