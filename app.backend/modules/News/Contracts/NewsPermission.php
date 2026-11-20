<?php

declare(strict_types=1);

namespace Acadly\Modules\News\Contracts;

use Acadly\Kernel\Access\Permission;

/** News' permission (D-101). Held at a division or the whole school. */
enum NewsPermission: string implements Permission
{
    case Publish = 'news.publish';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return 'Publish news';
    }

    public function help(): string
    {
        return 'Write, change and withdraw news for the division: to everyone, a faculty, a department, a programme, a year of study, or staff only.';
    }

    public function isSight(): bool
    {
        return false;
    }
}
