<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

use Acadly\Kernel\Identity\User;

/**
 * A module's part of the learner record (the approved learner dashboard):
 * everything about one learner is in one place. Every module that keeps
 * anything about a learner implements this and tags it
 * `LearnerRecordPart::TAG`; a test fails the build when a module that tells
 * families about a learner has no part. Its key is the module's key, so the
 * part shows only while the module is on. The record gives its tab and the
 * numbers it adds to the band; the module's own address gives the rest.
 *
 * @phpstan-type BandCell array{label: string, value: string, note: string, tone: 'plain'|'attention'}
 */
interface LearnerRecordPart
{
    public const string TAG = 'acadly.learner-record-parts';

    /** The module's key: 'attendance'. */
    public function key(): string;

    /** The name of its tab on the record: 'Attendance'. */
    public function title(): string;

    /**
     * What it adds to the band at the top of the record, for a learner the viewer may see.
     *
     * @return list<BandCell>
     */
    public function band(User $viewer, string $learnerId): array;

    /** Whether something about the learner needs attention: the tab shows it. */
    public function attention(User $viewer, string $learnerId): int;
}
