<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents;

use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Audit\FieldClass;
use BackedEnum;

/**
 * One kind of document a module keeps, such as a marked examination script
 * or a passport photo (D-053). Modules never make document or file tables:
 * they declare kinds, and the kernel stores every document in one table,
 * each with exactly one owner record.
 *
 * The key is the enum value, `<module>.<kind>`.
 */
interface DocumentKind extends BackedEnum
{
    public function key(): string;

    /** The name people see: "Marked examination script". */
    public function label(): string;

    /** The audit type of the record that owns a document of this kind, such as `toy_errand`. */
    public function ownerType(): string;

    /**
     * Tells the kernel where an owner record lives, so access can be checked at its scope.
     *
     * @return class-string<DocumentOwner>
     */
    public function owner(): string;

    /**
     * The types accepted, by content, not by file name: `application/pdf`, `image/jpeg`.
     *
     * @return list<string>
     */
    public function accepts(): array;

    /** The largest file accepted, in bytes. The deployment's own limit also applies. */
    public function maxBytes(): int;

    /** Plain, personal or secret. Reading a personal or secret document is an audit line. */
    public function classification(): FieldClass;

    public function readPermission(): Permission;

    public function writePermission(): Permission;

    /** Accepted even when the licence's storage is full: records the school must keep. */
    public function essential(): bool;

    /**
     * The current version may be shown to anyone, signed in or not, such as
     * the school logo on the sign-in page. Only plain kinds may be public.
     */
    public function isPublic(): bool;
}
