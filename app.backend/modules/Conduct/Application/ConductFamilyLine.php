<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Application;

use Acadly\Core\Enrolment\Contracts\FamilyLine;

/** The conduct door on a child's screen in the portal (D-110): this term's counts, as "3 2" (commendations, then incidents). */
final class ConductFamilyLine implements FamilyLine
{
    public function __construct(private readonly FamilyConductEntries $entries) {}

    public function line(string $learnerId, ?string $classId): ?array
    {
        if (! $this->entries->shownFor($classId)) {
            return null;
        }

        $entries = $this->entries->read($learnerId)['entries'];

        if ($entries === []) {
            return null;
        }

        $now = array_filter($entries, fn (array $e): bool => $e['this_term']);
        $commendations = count(array_filter($now, fn (array $e): bool => $e['commendation']));

        return ['key' => 'conduct', 'word' => 'conduct', 'label' => $commendations.' '.(count($now) - $commendations), 'tone' => 'plain'];
    }
}
