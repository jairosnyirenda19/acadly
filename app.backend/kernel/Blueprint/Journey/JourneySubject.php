<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Audit\Auditable;
use Acadly\Kernel\Blueprint\BlueprintRef;

/**
 * A record that travels a journey (docs/architecture/02-blueprints.md §3.5).
 *
 * The record stores its pinned journey ref and its state. Neither has a
 * public setter: the state column is written only by the journey engine,
 * and a database trigger refuses any other change (`JourneyColumns`).
 * The audit type and id name the record in step completions and effects.
 */
interface JourneySubject extends Auditable
{
    /** The version this record was pinned to when it was made. */
    public function journeyRef(): BlueprintRef;

    /** The value of the current state. */
    public function journeyState(): string;

    /** Where the record lives: the scope at which permissions are checked. */
    public function journeyScope(): ScopeRef;

    /** The table that holds the state column. Its key column is `id`. */
    public function journeyTable(): string;

    public function journeyStateColumn(): string;

    /** Called by the engine after it has written a new state, so the object agrees with the row. */
    public function journeyStateCommitted(string $state): void;

    /** Reads the record again, for work that runs after commit. */
    public static function findJourneySubject(string $id): ?static;
}
