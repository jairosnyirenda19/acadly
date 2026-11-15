<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Compiled;

/** A gate or an effect as compiled: its class, the class of its arguments, and their values. */
final readonly class CompiledCall
{
    /** @param  array<string, scalar|null>  $args */
    public function __construct(
        public string $class,
        public ?string $argsClass,
        public array $args,
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(Shape::string($data, 'class'), Shape::nullableString($data, 'args_class'), Shape::scalars($data, 'args'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['class' => $this->class, 'args_class' => $this->argsClass, 'args' => $this->args];
    }
}
