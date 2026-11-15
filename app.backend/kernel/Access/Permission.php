<?php

declare(strict_types=1);

namespace Acadly\Kernel\Access;

use BackedEnum;

/**
 * One permission: a function a person may perform
 * (docs/architecture/03-deployment-campus-access.md §5).
 *
 * Every permission is a case of an enum that a module declares in its
 * Contracts namespace and lists in its manifest. The key is the enum value,
 * `<module>.<function>`. A permission string in code fails the lint.
 */
interface Permission extends BackedEnum
{
    /** `<module>.<function>`, for example `attendance.correct`. */
    public function key(): string;

    /** A short name for the roles screen. */
    public function label(): string;

    /** One plain sentence: what the permission allows, and its limits. */
    public function help(): string;

    /**
     * A sight widens which records a person can view. A sight is never part
     * of a default role; it is given to a person on purpose.
     */
    public function isSight(): bool;
}
