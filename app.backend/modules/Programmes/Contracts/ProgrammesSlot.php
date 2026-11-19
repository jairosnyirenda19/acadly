<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Contracts;

use Acadly\Kernel\Blueprint\Profile\BindingSlot;
use BackedEnum;

/** The choice Programmes leaves to each division's profile (02 §2.4, D-097). */
enum ProgrammesSlot: string implements BindingSlot
{
    /** Whether the division's study is set by levels and classes, or by programmes and courses. */
    case Shape = 'programmes.shape';

    public function key(): string
    {
        return $this->value;
    }

    public function accepts(): string
    {
        return StudyShape::class;
    }

    public function fallback(): BackedEnum
    {
        return StudyShape::Classes;
    }
}
