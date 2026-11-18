<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

/** The kernel's own audit actions: accounts, access and the trail itself. */
enum KernelAudit: string implements AuditAction
{
    case SignedIn = 'identity.signed_in';
    case SignInFailed = 'identity.sign_in_failed';
    case SignInPaused = 'identity.sign_in_paused';
    case SignInCodeSent = 'identity.sign_in_code_sent';
    case SignInCodeRefused = 'identity.sign_in_code_refused';
    case AccountPhoneAdded = 'identity.account_phone_added';
    case AccountPhoneRemoved = 'identity.account_phone_removed';
    case SignedOut = 'identity.signed_out';
    case ResetRequested = 'identity.reset_requested';
    case PasswordReset = 'identity.password_reset';
    case PasswordChanged = 'identity.password_changed';
    case TwoStepOn = 'identity.two_step_on';
    case TwoStepOff = 'identity.two_step_off';
    case TwoStepAsked = 'identity.two_step_asked';
    case AccountInvited = 'identity.account_invited';
    case InvitationSent = 'identity.invitation_sent';
    case InvitationAccepted = 'identity.invitation_accepted';
    case AccountDisabled = 'identity.account_disabled';
    case ResetSent = 'identity.reset_sent';
    case PeopleImported = 'identity.people_imported';
    case ViewAsStarted = 'identity.view_as_started';
    case ViewAsEnded = 'identity.view_as_ended';
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
    case ChannelSaved = 'notify.channel_saved';
    case LimitsSaved = 'notify.limits_saved';
    case NoticeSaved = 'notify.notice_saved';
    case WordingReset = 'notify.wording_reset';
    case TestSent = 'notify.test_sent';
    case SentAgain = 'notify.sent_again';
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
    case ExportRequested = 'documents.export_requested';
    case ExportDownloaded = 'documents.export_downloaded';
    case ApprovalAsked = 'approvals.asked';
    case ApprovalAnswered = 'approvals.answered';
    case ChainSaved = 'approvals.chain_saved';
    case ApprovalWithdrawn = 'approvals.withdrawn';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::SignedIn => 'Signed in.',
            self::SignInFailed => 'Attempted to sign in with an incorrect email address, password or code.',
            self::SignInPaused => 'Attempted to sign in while sign-in was paused after repeated failures.',
            self::SignInCodeSent => 'Asked for a sign-in code by SMS.',
            self::SignInCodeRefused => 'Asked for a sign-in code for a phone number the school does not keep.',
            self::AccountPhoneAdded => 'Added a phone number to their own account, so the parent portal opens it.',
            self::AccountPhoneRemoved => 'Removed the phone number from their own account.',
            self::SignedOut => 'Signed out.',
            self::ResetRequested => 'Requested a password reset link.',
            self::PasswordReset => 'Set a new password with a reset link.',
            self::PasswordChanged => 'Changed their own password.',
            self::TwoStepOn => 'Turned on two-step sign-in.',
            self::TwoStepOff => 'Turned off two-step sign-in.',
            self::TwoStepAsked => 'Gave the right password and was asked for the second step.',
            self::AccountInvited => 'Made an account for a member of staff.',
            self::InvitationSent => 'Sent an invitation to set a password.',
            self::InvitationAccepted => 'Set a first password with an invitation link.',
            self::AccountDisabled => 'Disabled an account.',
            self::ResetSent => 'Sent a password reset link to a member of staff.',
            self::PeopleImported => 'Invited people and gave roles from a spreadsheet.',
            self::ViewAsStarted => 'Began to view the system as another account, for testing.',
            self::ViewAsEnded => 'Stopped viewing the system as another account.',
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
            self::ChannelSaved => 'Changed how messages are sent.',
            self::LimitsSaved => 'Changed the quiet hours or the SMS allowance.',
            self::NoticeSaved => 'Changed a notice: its words, whether it is sent, or who is told.',
            self::WordingReset => 'Returned a notice to the product wording.',
            self::TestSent => 'Sent a test message.',
            self::SentAgain => 'Sent a message again after it was not delivered.',
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
            self::ExportRequested => 'Asked for an export of a list.',
            self::ExportDownloaded => 'Downloaded an export of a list.',
            self::ApprovalAsked => 'Asked for an approval.',
            self::ApprovalAnswered => 'Approved or declined a step of an approval.',
            self::ChainSaved => 'Changed who approves a kind of request.',
            self::ApprovalWithdrawn => 'Withdrew a request for an approval.',
        };
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::RoleEnded, self::AccountDisabled, self::ModuleSwitched, self::DocumentTrashed, self::DocumentHeld, self::HoldReleased], true);
    }
}
