<?php

declare(strict_types=1);

namespace Acadly\Modules\News\Contracts;

use Acadly\Kernel\Audit\AuditAction;

/** What News writes to the audit trail (D-101). */
enum NewsAudit: string implements AuditAction
{
    case Published = 'news.published';
    case Edited = 'news.edited';
    case Withdrawn = 'news.withdrawn';

    public function key(): string
    {
        return $this->value;
    }

    public function sentence(): string
    {
        return match ($this) {
            self::Published => 'Published news.',
            self::Edited => 'Changed published news.',
            self::Withdrawn => 'Withdrew news.',
        };
    }

    public function requiresReason(): bool
    {
        return $this === self::Withdrawn;
    }
}
