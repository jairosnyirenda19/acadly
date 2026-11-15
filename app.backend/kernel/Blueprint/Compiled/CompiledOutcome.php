<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Compiled;

final readonly class CompiledOutcome
{
    /**
     * @param  list<CompiledCall>  $effects
     * @param  list<string>  $emits  class names of journey events
     */
    public function __construct(
        public string $id,
        public string $title,
        public bool $requiresReason,
        public array $effects,
        public string $to,
        public array $emits,
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Shape::string($data, 'id'),
            Shape::string($data, 'title'),
            Shape::bool($data, 'requires_reason'),
            array_map(CompiledCall::fromArray(...), Shape::records($data, 'effects')),
            Shape::string($data, 'to'),
            Shape::strings($data, 'emits'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'requires_reason' => $this->requiresReason,
            'effects' => array_map(fn (CompiledCall $call): array => $call->toArray(), $this->effects),
            'to' => $this->to,
            'emits' => $this->emits,
        ];
    }
}
