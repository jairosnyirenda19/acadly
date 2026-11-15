<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

/** How a contact is related to a learner. */
enum Relationship: string
{
    case Mother = 'mother';
    case Father = 'father';
    case Guardian = 'guardian';
    case Grandparent = 'grandparent';
    case AuntUncle = 'aunt_uncle';
    case Sibling = 'sibling';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Mother => 'Mother',
            self::Father => 'Father',
            self::Guardian => 'Guardian',
            self::Grandparent => 'Grandparent',
            self::AuntUncle => 'Aunt or uncle',
            self::Sibling => 'Brother or sister',
            self::Other => 'Other',
        };
    }
}
