<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

/** A named group of steps, used for the progress ladder. */
final class Stage
{
    /** @var list<Step> */
    private array $steps = [];

    public function __construct(
        public readonly string $key,
        public readonly string $title,
    ) {}

    public function step(Step $step): self
    {
        $this->steps[] = $step;

        return $this;
    }

    /** @return list<Step> */
    public function steps(): array
    {
        return $this->steps;
    }
}
