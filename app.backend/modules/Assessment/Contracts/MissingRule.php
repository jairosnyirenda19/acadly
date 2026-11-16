<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Contracts;

/**
 * What a part does with work missing without an excuse (a learner absent).
 * Excused and exempt work is always left out.
 */
enum MissingRule: string
{
    case LeftOut = 'left_out';
    case Zero = 'zero';
    case Ask = 'ask';

    public function label(): string
    {
        return match ($this) {
            self::LeftOut => 'Missing work is left out.',
            self::Zero => 'Missing and excused: left out. Missing without a reason: zero.',
            self::Ask => 'Missing: the teacher decides before submitting.',
        };
    }
}
