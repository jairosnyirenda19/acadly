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
    case AccountInvited = 'identity.account_invited';
    case InvitationSent = 'identity.invitation_sent';
    case InvitationAccepted = 'identity.invitation_accepted';
    case AccountDisabled = 'identity.account_disabled';
    case ResetSent = 'identity.reset_sent';
    case PeopleImported = 'identity.people_imported';
    case FirstAdministrator = 'access.first_administrator';
    case RoleAssigned = 'access.role_assigned';
    case RoleEnded = 'access.role_ended';
    case RoleCreated = 'access.role_created';
    case RoleChanged = 'access.role_changed';
    case RoleRetired = 'access.role_retired';
    case RoleDeleted = 'access.role_deleted';
    case Refused = 'access.refused';
    case ModuleSwitched = 'modules.switched';
    case LicenceInstalled = 'modules.licence_installed';
    case StepPerformed = 'journeys.step_performed';
    case Subscribed = 'notify.subscribed';
    case Unsubscribed = 'notify.unsubscribed';
    case PreferenceChanged = 'notify.preference_changed';
    case DocumentStored = 'documents.stored';
    case VersionAdded = 'documents.version_added';
    case VersionRestored = 'documents.version_restored';
    case DocumentLinked = 'documents.linked';
    case DocumentUnlinked = 'documents.unlinked';
    case DocumentTrashed = 'documents.trashed';
    case DocumentRestored = 'documents.restored';
    case DocumentPurged = 'documents.purged';
    case DocumentHeld = 'documents.held';
    case HoldReleased = 'documents.hold_released';
    case DocumentRead = 'documents.read';
    case UploadRefused = 'documents.upload_refused';
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
            self::AccountInvited => 'Made an account for a member of staff.',
            self::InvitationSent => 'Sent an invitation to set a password.',
            self::InvitationAccepted => 'Set a first password with an invitation link.',
            self::AccountDisabled => 'Disabled an account.',
            self::ResetSent => 'Sent a password reset link to a member of staff.',
            self::PeopleImported => 'Invited people and gave roles from a spreadsheet.',
            self::FirstAdministrator => 'Created the first administrator of the deployment.',
            self::RoleAssigned => 'Gave a role.',
            self::RoleEnded => 'Ended a role.',
            self::RoleCreated => 'Created a role.',
            self::RoleChanged => 'Changed the name or the permissions of a role.',
            self::RoleRetired => 'Retired a role nobody holds any longer.',
            self::RoleDeleted => 'Deleted a role that was never held.',
            self::Refused => 'Attempted an action without the required permission.',
            self::ModuleSwitched => 'Switched a module on or off.',
            self::LicenceInstalled => 'Installed a licence.',
            self::StepPerformed => 'Performed a step of a process on a record.',
            self::Subscribed => 'Subscribed to a notice.',
            self::Unsubscribed => 'Ended a subscription to a notice.',
            self::PreferenceChanged => 'Changed how a notice is received.',
            self::DocumentStored => 'Added a document.',
            self::VersionAdded => 'Added a new version of a document.',
            self::VersionRestored => 'Restored an earlier version of a document.',
            self::DocumentLinked => 'Linked a document to a record.',
            self::DocumentUnlinked => 'Removed the link between a document and a record.',
            self::DocumentTrashed => 'Moved a document to the trash.',
            self::DocumentRestored => 'Restored a document from the trash.',
            self::DocumentPurged => 'Emptied a document from the trash.',
            self::DocumentHeld => 'Placed a hold on a document.',
            self::HoldReleased => 'Released the hold on a document.',
            self::DocumentRead => 'Opened a document.',
            self::UploadRefused => 'Attempted to upload a file that was refused.',
            self::Sealed => 'Sealed the audit trail.',
            self::ChainBroken => 'Found that the audit trail does not verify.',
        };
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::RoleEnded, self::AccountDisabled, self::ModuleSwitched, self::DocumentTrashed, self::DocumentHeld, self::HoldReleased], true);
    }
}
