<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Domain;

use Acadly\Core\Enrolment\Contracts\EnrolmentDocument;

/** Who may open the documents of a learner document type (D-090). */
enum LearnerDocumentAccess: string
{
    case Staff = 'staff';
    case Office = 'office';
    case Confidential = 'confidential';

    /** The record kind its documents are kept as. */
    public function kind(): EnrolmentDocument
    {
        return match ($this) {
            self::Staff => EnrolmentDocument::Record,
            self::Office => EnrolmentDocument::RecordOffice,
            self::Confidential => EnrolmentDocument::RecordConfidential,
        };
    }
}
