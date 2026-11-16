<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Contracts;

use Acadly\Kernel\Blueprint\Profile\BindingSlot;
use BackedEnum;

/** The choices Assessment leaves to each level's profile (02 §2.4). */
enum AssessmentSlot: string implements BindingSlot
{
    /** Whether the level takes marks or records observations. */
    case Model = 'assessment.model';

    public function key(): string
    {
        return $this->value;
    }

    public function accepts(): string
    {
        return match ($this) {
            self::Model => AssessmentModel::class,
        };
    }

    public function fallback(): BackedEnum
    {
        return match ($this) {
            self::Model => AssessmentModel::Marks,
        };
    }
}
