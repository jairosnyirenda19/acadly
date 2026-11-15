<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents;

use Acadly\Kernel\Access\Permission;

/** The kernel's own document permission: holds, storage, and documents whose owner record is gone. */
enum DocumentPermission: string implements Permission
{
    case Manage = 'documents.manage';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return 'Manage documents';
    }

    public function help(): string
    {
        return 'Place and release holds on documents, and look after storage. Does not open documents by itself.';
    }

    public function isSight(): bool
    {
        return false;
    }
}
