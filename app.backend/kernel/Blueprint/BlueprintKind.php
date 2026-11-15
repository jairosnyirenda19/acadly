<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint;

/** The two kinds of blueprint (D-008): the shape of a division, and the movement of a record. */
enum BlueprintKind: string
{
    case Profile = 'profile';
    case Journey = 'journey';
}
