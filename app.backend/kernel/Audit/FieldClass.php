<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

/**
 * How a field appears in an audit diff (docs/architecture/10-audit-trail.md §4).
 *
 *  - secret:   masked on both sides; only the fact of the change is kept.
 *  - personal: kept in an encrypted side record that can be erased later.
 *  - plain:    kept in the line itself.
 */
enum FieldClass: string
{
    case Secret = 'secret';
    case Personal = 'personal';
    case Plain = 'plain';
}
