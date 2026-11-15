<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

/**
 * Finds the people a notice concerns: the family of a child, the applicant,
 * the person whose request was decided. Owned by the module that knows the
 * relation, and run at send time, so a relation that ended receives nothing.
 */
interface PartyResolver
{
    /** @return list<Recipient> */
    public function resolve(Topic $topic): array;
}
