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
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        public readonly string $title,
    ) {
        parent::__construct($title);
    }

    public static function moduleInactive(): self
    {
        return new self(403, 'module_inactive', 'This function is not available for the school.');
    }

    public static function readOnly(): self
    {
        return new self(403, 'licence_read_only', 'The licence is not current. Records can be viewed and exported, not changed.');
    }
}
