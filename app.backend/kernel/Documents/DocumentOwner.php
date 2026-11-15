<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents;

use Acadly\Kernel\Access\ScopeRef;

/**
 * Answers for the module that owns a kind of record: where a record lives,
 * and what to call it. The kernel checks document access at that scope.
 */
interface DocumentOwner
{
    /** The scope of the record, or null when the record does not exist. */
    public function scopeOf(string $ownerId): ?ScopeRef;

    /** What a reader of the audit trail should see for the record. */
    public function labelOf(string $ownerId): string;
}
