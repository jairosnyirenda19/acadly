<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

/** What an effect produced: the id of a record it made, if any, and plain facts. */
final readonly class EffectResult
{
    /** @param  array<string, scalar|null>  $meta */
    public function __construct(
        public ?string $produced = null,
        public array $meta = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public static function produced(string $id): self
    {
        return new self($id);
    }
}
