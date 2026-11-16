<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

/** Who an audit line is about as the doer. Copied onto the line. */
final readonly class Actor
{
    /**
     * @param  list<string>  $roles  role keys held when the line was written
     * @param  string|null  $actingForUserId  the account the doer acted as or for (View as)
     */
    public function __construct(
        public ActorKind $kind,
        public ?string $userId,
        public string $label,
        public array $roles = [],
        public ?string $actingForUserId = null,
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
