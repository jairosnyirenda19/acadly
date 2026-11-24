<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Modules\Attendance\Contracts\AwayByDecision;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;

/**
 * The learners a decision keeps out of school on a day (D-111): a
 * suspension, for example. Each decision covers its number of school days
 * of the learner's division from its first day, and stops early when it was
 * ended early. The register marks them Excused and names the decision.
 */
final class DecidedAbsences
{
    public function __construct(
        private readonly Container $container,
        private readonly SchoolDays $days,
        private readonly EnrolmentDirectory $directory,
    ) {}

    /**
     * @param  list<string>  $learnerIds
     * @return array<string, array{label: string, reference: string, from: string, to: string, days: list<string>, note: string}> learner id => the decision on that day
     */
    public function on(array $learnerIds, string $day): array
    {
        $out = [];

        foreach ($this->decisions($learnerIds) as $learner => $list) {
            foreach ($list as $d) {
                if (in_array($day, $d['days'], true)) {
                    $out[$learner] = $d;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Every decision of some learners, with the school days it covers.
     *
     * @param  list<string>  $learnerIds
     * @return array<string, list<array{label: string, reference: string, from: string, to: string, days: list<string>, note: string}>>
     */
    public function decisions(array $learnerIds): array
    {
        if ($learnerIds === []) {
            return [];
        }

        $out = [];

        /** @var iterable<AwayByDecision> $sources */
        $sources = $this->container->tagged(AwayByDecision::TAG);

        foreach ($sources as $source) {
            foreach ($source->decisions($learnerIds) as $learner => $list) {
                $division = $this->directory->placeOf($learner)['division_id'] ?? null;

                if ($division === null) {
                    continue;
                }

                foreach ($list as $d) {
                    $days = $this->covered($division, $d['starts_on'], $d['days'], $d['ended_after']);

                    if ($days === []) {
                        continue;
                    }

                    $out[$learner][] = ['label' => $d['label'], 'reference' => $d['reference'], 'from' => $days[0], 'to' => $days[count($days) - 1], 'days' => $days,
                        'note' => $d['label'].' · '.$d['reference']];
                }
            }
        }

        return $out;
    }

    /** @return list<string> the school days a decision covers */
    private function covered(string $divisionId, string $startsOn, int $count, ?string $endedAfter): array
    {
        $out = [];
        $at = new DateTimeImmutable($startsOn);

        for ($i = 0; $i < 200 && count($out) < $count; $i++, $at = $at->modify('+1 day')) {
            $day = $at->format('Y-m-d');

            if ($endedAfter !== null && $day > $endedAfter) {
                break;
            }

            if ($this->days->isSchoolDay($divisionId, $day)) {
                $out[] = $day;
            }
        }

        return $out;
    }
}
