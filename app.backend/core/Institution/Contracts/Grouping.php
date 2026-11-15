<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Contracts;

/**
 * How a division forms its classes. In each case a level with no stream is
 * one class, named after the level; a stream named once can be used at
 * every level that needs it.
 */
enum Grouping: string
{
    /** One class per level, divided into streams where the school has them. */
    case Streams = 'streams';

    /** Rooms by age group, as in a nursery. */
    case Rooms = 'rooms';
}
