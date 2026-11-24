<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Enrolment\Contracts\FamilyLine;

/** The documents door on a child's screen in the portal (D-110): how many documents the family sees. */
final class DocumentsFamilyLine implements FamilyLine
{
    public function line(string $learnerId, ?string $classId): ?array
    {
        $count = count(FamilyDocuments::sharedOf($learnerId));

        return $count === 0 ? null : ['key' => 'documents', 'word' => 'documents', 'label' => (string) $count, 'tone' => 'plain'];
    }
}
