<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access\Events;

use Acadly\Kernel\Events\DomainEvent;

/** A role assignment was ended. */
final readonly class RoleEnded implements DomainEvent
{
    public function __construct(
        public string $assignmentId,
        public string $userId,
        public string $roleKey,
        public ?string $endedBy,
    ) {}

    public static function eventName(): string
    {
        return 'access.role_ended';
    }

    public function toPayload(): array
    {
        return [
            'assignment_id' => $this->assignmentId,
            'user_id' => $this->userId,
            'role_key' => $this->roleKey,
            'ended_by' => $this->endedBy,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        $text = static fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self(
            $text('assignment_id'),
            $text('user_id'),
            $text('role_key'),
            is_string($payload['ended_by'] ?? null) ? $payload['ended_by'] : null,
        );
    }
}
