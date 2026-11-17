<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

/**
 * A module's line on a learner's report card (D-080): attendance gives
 * "58 of 62 days". The module that publishes results asks every tagged line
 * while its module is on, and keeps what it said in the published card.
 * Tag it `ReportCardLine::TAG`; its key is the module's key.
 */
interface ReportCardLine
{
    public const string TAG = 'acadly.report-card-lines';

    /** The module's key: 'attendance'. */
    public function key(): string;

    /**
     * The line of each learner for the days from and to, inclusive. A learner
     * with nothing to say is left out.
     *
     * @param  list<string>  $learnerIds
     * @return array<string, array{label: string, value: string}>
     */
    public function lines(array $learnerIds, string $from, string $to): array;
}
