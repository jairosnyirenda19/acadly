<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module\Events;

use Acadly\Kernel\Events\DomainEvent;

/** The school switched a module on or off. */
final readonly class ModuleSwitched implements DomainEvent
{
    public function __construct(
        public string $module,
        public bool $enabled,
        public ?string $switchedBy,
    ) {}

    public static function eventName(): string
    {
        return 'modules.switched';
    }

    public function toPayload(): array
    {
        return ['module' => $this->module, 'enabled' => $this->enabled, 'switched_by' => $this->switchedBy];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            is_string($payload['module'] ?? null) ? $payload['module'] : '',
            ($payload['enabled'] ?? false) === true,
            is_string($payload['switched_by'] ?? null) ? $payload['switched_by'] : null,
        );
    }
}
