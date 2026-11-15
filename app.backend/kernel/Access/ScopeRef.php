<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

use Acadly\Kernel\Support\Ids;
use InvalidArgumentException;

/**
 * A place in the school: the whole institution, or one division, campus or
 * class. The institution has no identifier: a deployment holds one.
 */
final readonly class ScopeRef
{
    private function __construct(
        public ScopeType $type,
        public ?string $id,
    ) {}

    public static function institution(): self
    {
        return new self(ScopeType::Institution, null);
    }

    public static function of(ScopeType $type, ?string $id): self
    {
        if ($type === ScopeType::Institution) {
            if ($id !== null) {
                throw new InvalidArgumentException('The institution scope has no identifier.');
            }

            return self::institution();
        }

        if ($id === null || ! Ids::isValid($id)) {
            throw new InvalidArgumentException("A {$type->value} scope needs a valid identifier.");
        }

        return new self($type, $id);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
