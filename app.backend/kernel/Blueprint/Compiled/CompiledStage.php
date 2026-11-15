<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Compiled;

final readonly class CompiledStage
{
    /** @param  list<string>  $steps */
    public function __construct(
        public string $key,
        public string $title,
        public array $steps,
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(Shape::string($data, 'key'), Shape::string($data, 'title'), Shape::strings($data, 'steps'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'title' => $this->title, 'steps' => $this->steps];
    }
}
