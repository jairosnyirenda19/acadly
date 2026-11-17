<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

/**
 * One line on a child's card in the parent portal, given by the module that
 * owns it: "At school today", "New: Term 1 report card". Tagged with TAG.
 * A line carries a word as well as a tone, never a colour alone.
 */
interface FamilyLine
{
    public const string TAG = 'enrolment.family_lines';

    /**
     * The line for this child now, or null when there is nothing to say.
     *
     * The word is a key the portal turns into words in the parent's language;
     * the label is a name to show as it is, such as a term name.
     *
     * @return array{key: string, word: string, label: string|null, tone: 'done'|'attention'|'wrong'|'plain'}|null
     */
    public function line(string $learnerId, ?string $classId): ?array;
}
