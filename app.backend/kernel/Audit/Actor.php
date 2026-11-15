<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

/** Who an audit line is about as the doer. Copied onto the line. */
final readonly class Actor
{
    /** @param  list<string>  $roles  role keys held when the line was written */
    public function __construct(
        public ActorKind $kind,
        public ?string $userId,
        public string $label,
        public array $roles = [],
    ) {}

    public static function system(string $label = 'System'): self
    {
        return new self(ActorKind::System, null, $label);
    }

    public static function anonymous(): self
    {
        return new self(ActorKind::Anonymous, null, 'No account');
    }
}
