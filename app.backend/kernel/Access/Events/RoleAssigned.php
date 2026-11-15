<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access\Events;

use Acadly\Kernel\Events\DomainEvent;

/** An account was given a role at a scope. */
final readonly class RoleAssigned implements DomainEvent
{
    public function __construct(
        public string $assignmentId,
        public string $userId,
        public string $roleKey,
        public string $scopeType,
        public ?string $scopeId,
        public bool $includesSight,
        public ?string $assignedBy,
    ) {}

    public static function eventName(): string
    {
        return 'access.role_assigned';
    }

    public function toPayload(): array
    {
        return [
            'assignment_id' => $this->assignmentId,
            'user_id' => $this->userId,
            'role_key' => $this->roleKey,
            'scope_type' => $this->scopeType,
            'scope_id' => $this->scopeId,
            'includes_sight' => $this->includesSight,
            'assigned_by' => $this->assignedBy,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        $text = static fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';
        $maybe = static fn (string $key): ?string => is_string($payload[$key] ?? null) ? $payload[$key] : null;

        return new self(
            $text('assignment_id'),
            $text('user_id'),
            $text('role_key'),
            $text('scope_type'),
            $maybe('scope_id'),
            ($payload['includes_sight'] ?? false) === true,
            $maybe('assigned_by'),
        );
    }
}
