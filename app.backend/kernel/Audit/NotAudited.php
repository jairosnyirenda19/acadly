<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

use Attribute;

/**
 * Marks an Action that writes no audit line, and says why. Every other Action
 * must write one (docs/architecture/10-audit-trail.md §7).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class NotAudited
{
    public function __construct(public string $why) {}
}
