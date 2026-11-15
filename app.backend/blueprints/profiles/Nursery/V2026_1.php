<?php

declare(strict_types=1);

use Acadly\Core\Institution\Contracts\Grouping;
use Acadly\Core\Institution\Contracts\Term;
use Acadly\Kernel\Blueprint\Profile\ProfileBlueprint;

return ProfileBlueprint::make('ECD@2026.1')
    ->title('Nursery')
    ->summary('Children from 3 to 5 years. Rooms, caregivers and observations instead of marks.')
    ->extends('SCHOOL_BASE@2026.1')
    ->term(Term::Learner, 'Child', 'Children')
    ->term(Term::Level, 'Age group', 'Age groups')
    ->term(Term::Group, 'Room', 'Rooms')
    ->levels(['BABY' => 'Baby class', 'MIDDLE' => 'Middle class', 'TOP' => 'Top class'])
    ->grouping(Grouping::Rooms);
