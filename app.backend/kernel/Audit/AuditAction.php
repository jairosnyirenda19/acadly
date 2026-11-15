<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

use BackedEnum;

/**
 * One kind of audit line (docs/architecture/10-audit-trail.md §7).
 *
 * Every audit action is a case of an enum that the kernel or a module
 * declares, like a permission. The key is the enum value,
 * `<owner>.<what_happened>`. The screens print the sentence, never the key.
 */
interface AuditAction extends BackedEnum
{
    /** `<owner>.<what_happened>`, for example `access.role_assigned`. */
    public function key(): string;

    /** One plain sentence in the past tense: "Gave a role." */
    public function sentence(): string;

    /** A line of this kind is refused without a reason. */
    public function requiresReason(): bool;
}
