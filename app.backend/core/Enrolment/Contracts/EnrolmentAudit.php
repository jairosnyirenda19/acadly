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
        };
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::DetailsCorrected, self::ClassChanged, self::ContactRemoved], true);
    }
}
