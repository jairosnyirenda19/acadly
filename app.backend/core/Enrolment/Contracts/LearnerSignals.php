<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

/**
 * A module's signals that a learner may need a closer look, and its figures
 * by term (D-093). Tagged `LearnerSignals::TAG`; its key is the module's
 * key, so it counts only while the module is on.
 *
 * A signal always says why, in words ("78% present this term, down from
 * 92%."). It is a reason to look closer, never a score or a prediction.
 * Fees are never a signal.
 *
 * @phpstan-type Rule array{key: string, text: string, default: int}
 * @phpstan-type Term array{key: string, name: string, starts_on: string, ends_on: string}
 * @phpstan-type Trend array{key: string, label: string, unit: string, values: array<string, float|null>, worse: 'up'|'down'}
 */
interface LearnerSignals
{
    public const string TAG = 'acadly.learner-signals';

    /** The module's key: 'attendance'. */
    public function key(): string;

    /** The source, as people see it: 'Attendance'. */
    public function label(): string;

    /**
     * The rules it reads, each with one number: "Present less than {value}% this term".
     * A value of 0 turns the rule off.
     *
     * @return list<Rule>
     */
    public function rules(): array;

    /**
     * Why each learner may need a closer look, by the school's values of the rules.
     *
     * @param  list<string>  $learnerIds
     * @param  array<string, int>  $rules  by rule key
     * @return array<string, list<string>> learner id => reasons; a learner with none may be left out
     */
    public function signals(array $learnerIds, array $rules): array;

    /**
     * One learner's figures for each term, oldest first.
     *
     * @param  list<Term>  $terms
     * @return list<Trend>
     */
    public function trends(string $learnerId, array $terms): array;
}
