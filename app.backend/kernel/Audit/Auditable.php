<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

/**
 * A record that audit lines can name. The label is copied onto each line, so
 * the line stays readable after the record changes or is removed.
 */
interface Auditable
{
    /** A stable type name for the trail, such as `account`. Not a class name. */
    public function auditType(): string;

    public function auditId(): string;

    /** What a reader should see: a name, a code. */
    public function auditLabel(): string;

    /**
     * The fields that are audited, each with its class. A field that is not
     * listed never appears in a diff.
     *
     * @return array<string, FieldClass>
     */
    public function auditFields(): array;
}
