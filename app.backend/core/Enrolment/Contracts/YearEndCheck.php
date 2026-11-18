<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

/**
 * What a module tells the year-end run (D-088): a column that helps the
 * office decide, such as attendance or the last report card, and what must
 * be put right before the run is committed, such as results not published.
 * Tagged with TAG. The run never decides an outcome from these on its own.
 */
interface YearEndCheck
{
    public const string TAG = 'enrolment.year_end_checks';

    /**
     * A column beside each learner, or null when the module has none.
     *
     * @param  list<string>  $learnerIds
     * @return array{label: string, values: array<string, string>}|null
     */
    public function column(string $yearId, array $learnerIds): ?array;

    /**
     * What stops the commit, in sentences an office reads.
     *
     * @param  list<string>  $classIds  the classes of the run, this year
     * @return list<string>
     */
    public function problems(string $yearId, array $classIds): array;
}
