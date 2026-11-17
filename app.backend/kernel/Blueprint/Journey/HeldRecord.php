<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

/**
 * A journey record that belongs to one account: a parent's link to a child.
 *
 * A step declared `byHolder()` is taken by that account alone, with no
 * permission: a person acts on their own record (03 §5.7 rule 7). Nobody
 * else may take it, whatever roles they hold.
 */
interface HeldRecord
{
    /** The account the record belongs to, or null while it belongs to nobody. */
    public function heldBy(): ?string;
}
