<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

use Acadly\Kernel\Audit\AuditAction;

enum EnrolmentAudit: string implements AuditAction
{
    case LearnerAdmitted = 'enrolment.learner_admitted';
    case DetailsCorrected = 'enrolment.details_corrected';
    case ClassChanged = 'enrolment.class_changed';
    case ContactAdded = 'enrolment.contact_added';
    case ContactRemoved = 'enrolment.contact_removed';
    case MainContactSet = 'enrolment.main_contact_set';
    case ContactChannelSet = 'enrolment.contact_channel_set';
    case PhotoReplaced = 'enrolment.photo_replaced';
    case LearnersImported = 'enrolment.learners_imported';
    case ParentLinkAsked = 'enrolment.parent_link_asked';
    case FamiliesInvited = 'enrolment.families_invited';
    case ProgressionOpened = 'enrolment.progression_opened';
    case ProgressionChanged = 'enrolment.progression_changed';
    case ProgressionCommitted = 'enrolment.progression_committed';
    case DocumentAdded = 'enrolment.document_added';
    case DocumentTypeSaved = 'enrolment.document_type_saved';
    case DocumentTypeRetired = 'enrolment.document_type_retired';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::LearnerAdmitted => 'Admitted a learner into a class.',
            self::DetailsCorrected => 'Corrected the details of a learner.',
            self::ClassChanged => 'Moved a learner to another class.',
            self::ContactAdded => 'Added a contact to a learner.',
            self::ContactRemoved => 'Removed a contact from a learner.',
            self::MainContactSet => 'Made a contact the main contact of a learner.',
            self::ContactChannelSet => 'Changed how a contact of a learner receives notices.',
            self::PhotoReplaced => 'Set the photo of a learner.',
            self::LearnersImported => 'Admitted learners from a spreadsheet.',
            self::ParentLinkAsked => 'Was asked by the parent portal to confirm a child.',
            self::FamiliesInvited => 'Invited families to the parent portal by text message.',
            self::ProgressionOpened => 'Opened the year-end run of a level group.',
            self::ProgressionChanged => 'Changed the year-end outcome of a learner.',
            self::ProgressionCommitted => 'Committed the year-end run of a level group.',
            self::DocumentAdded => 'Added a document to the record of a learner.',
            self::DocumentTypeSaved => 'Saved a learner document type.',
            self::DocumentTypeRetired => 'Retired a learner document type.',
        };
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::DetailsCorrected, self::ClassChanged, self::ContactRemoved], true);
    }
}
