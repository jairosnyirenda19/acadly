<?php

declare(strict_types=1);

namespace Acadly\Kernel\Events;

/**
 * Something that happened, told to other modules
 * (docs/architecture/05-data-and-api.md §5).
 *
 * A small immutable class in the owner's `Contracts/Events/`, named in the
 * past tense. It carries ids and facts, never whole models. It is written to
 * the outbox in the transaction of the change and delivered after commit.
 */
interface DomainEvent
{
    /** `<owner>.<what_happened>`, for example `access.role_assigned`. Never changes once released. */
    public static function eventName(): string;

    /**
     * The facts, as stored. Only scalars and null.
     *
     * @return array<string, scalar|null>
     */
    public function toPayload(): array;

    /** @param  array<string, mixed>  $payload */
    public static function fromPayload(array $payload): static;
}
