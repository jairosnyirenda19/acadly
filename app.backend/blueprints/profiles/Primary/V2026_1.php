<?php

declare(strict_types=1);

use Acadly\Core\Institution\Contracts\Grouping;
use Acadly\Core\Institution\Contracts\Term;
use Acadly\Kernel\Blueprint\Profile\ProfileBlueprint;

return ProfileBlueprint::make('PRIMARY@2026.1')
    ->title('Primary')
    ->summary('Standards 1 to 8. One class teacher per class. Marks and report cards.')
    ->extends('SCHOOL_BASE@2026.1')
    ->term(Term::Learner, 'Pupil', 'Pupils')
    ->term(Term::Level, 'Standard', 'Standards')
    ->levels([
        'STD1' => 'Standard 1', 'STD2' => 'Standard 2', 'STD3' => 'Standard 3', 'STD4' => 'Standard 4',
        'STD5' => 'Standard 5', 'STD6' => 'Standard 6', 'STD7' => 'Standard 7', 'STD8' => 'Standard 8',
    ])
    ->grouping(Grouping::Streams);
