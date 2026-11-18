<?php

declare(strict_types=1);

namespace Acadly\Kernel\Http;

use RuntimeException;

/**
 * A refusal with its own stable code and title, rendered as a problem
 * document. Use it when the plain status code does not say enough, such as
 * a module that is switched off.
 */
final class Problem extends RuntimeException
{
    /** @param  array<string, string>  $extra  further members of the problem document */
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        public readonly string $title,
        public readonly array $extra = [],
    ) {
        parent::__construct($title);
    }

    public static function moduleInactive(): self
    {
        return new self(403, 'module_inactive', 'This function is not available for the school.');
    }

    public static function stepNotAvailable(): self
    {
        return new self(409, 'step_not_available', 'This step is not available for the record in its current state.');
    }

    /** A journey gate is closed: the reason says what is not true yet, the remedy what makes it true. */
    public static function stepNotAdmissible(string $reason, string $remedy): self
    {
        return new self(422, 'step_not_admissible', 'The step cannot be performed yet.', ['reason' => $reason, 'remedy' => $remedy]);
    }

    public static function anotherPersonRequired(): self
    {
        return new self(403, 'another_person_required', 'This step must be performed by a different person.');
    }

    /** A session signed in with a code by SMS asked for more than the parent portal (D-087). */
    public static function passwordRequired(): self
    {
        return new self(403, 'password_required', 'Sign in with your email address and password to use the staff console.');
    }

    public static function stateChanged(): self
    {
        return new self(409, 'state_changed', 'The record changed while the request was in progress. Reload the record and try again.');
    }

    public static function readOnly(): self
    {
        return new self(403, 'licence_read_only', 'The licence is not current. Records can be viewed and exported, not changed.');
    }
}
