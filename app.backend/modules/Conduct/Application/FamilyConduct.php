<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Application;

use Acadly\Core\Enrolment\Contracts\Guardianship;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Phone;
use Acadly\Modules\Conduct\Journeys\SeriousIncident\State;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Conduct in the parent portal (D-110). A confirmed parent sees the entries
 * the family was told about, this school year: the date, the kind and, for a
 * serious incident, its stage and the decision for this child. Never the text
 * staff wrote, which can name other learners. A withdrawn entry is not shown.
 * Shown only while the school shows conduct to families and Conduct is on
 * where the child is placed. Anything else is "not found".
 *
 * @phpstan-import-type FamilyEntry from FamilyConductEntries
 *
 * @phpstan-type FamilySerious array{id: string, kind: string, happened_on: string, stage: string, decision: string|null, meeting: bool}
 */
final class FamilyConduct
{
    public function __construct(
        private readonly Guardianship $guardianship,
        private readonly FamilyConductEntries $read,
        private readonly SchoolProfile $school,
    ) {}

    /**
     * @return array{child: array{id: string, first_name: string, name: string}, term: string|null, commendations: int, incidents: int, serious: list<FamilySerious>, entries: list<FamilyEntry>, school_phone: string|null}
     */
    public function child(User $parent, string $learnerId): array
    {
        $child = $this->guardianship->child($parent, $learnerId);

        if ($child === null || ! $this->read->shownFor($child['class_id'])) {
            throw new NotFoundHttpException;
        }

        ['term' => $term, 'entries' => $entries, 'cases' => $cases] = $this->read->read($learnerId);
        $serious = [];

        foreach ($cases as $c) {
            if (in_array($c['state'], [State::Closed->value, State::Withdrawn->value], true)) {
                continue;
            }

            $decided = in_array($c['state'], Suspensions::DECIDED, true);
            $serious[] = ['id' => $c['entry_id'], 'kind' => $c['kind'], 'happened_on' => $c['happened_on'], 'stage' => State::from($c['state'])->label(),
                'decision' => $decided ? $c['decision'] : null, 'meeting' => $c['state'] === State::Decided->value];
        }

        return [
            'child' => ['id' => $child['id'], 'first_name' => $child['first_name'], 'name' => $child['name']],
            'term' => $term,
            'commendations' => count(array_filter($entries, fn (array $e): bool => $e['commendation'] && $e['this_term'])),
            'incidents' => count(array_filter($entries, fn (array $e): bool => ! $e['commendation'] && $e['this_term'])),
            'serious' => $serious,
            'entries' => $entries,
            'school_phone' => Phone::forPeople($this->school->phone()),
        ];
    }
}
