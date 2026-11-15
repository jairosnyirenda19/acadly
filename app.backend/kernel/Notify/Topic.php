<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Access\ScopeRef;

/**
 * One event worth telling people about, as a typed object (04 §2, §3).
 *
 * The class declares its notice once, with its words. A sender builds the
 * object with ids and facts and hands it to `Notifier::send()`. It is kept
 * as a payload until it is resolved after commit, so it carries only
 * scalars, like a domain event.
 */
interface Topic
{
    public static function notice(): Notice;

    /** The record the notice is about. */
    public function subject(): SubjectRef;

    /** Where it happened: desk subscriptions at this scope or a wider one hear it. */
    public function scope(): ScopeRef;

    /**
     * The facts the notice names, one per declared fact. Read when the
     * notice is resolved, after commit. A missing fact prints as a dash.
     *
     * @return array<string, string|null>
     */
    public function tokens(): array;

    /** Unique for the event. A second send of the same event is ignored. */
    public function dedupeKey(): string;

    /** @return array<string, scalar|null> */
    public function toPayload(): array;

    /** @param  array<string, mixed>  $payload */
    public static function fromPayload(array $payload): static;
}
