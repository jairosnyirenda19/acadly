<?php

declare(strict_types=1);

use Acadly\Core\Institution\Contracts\Grouping;
use Acadly\Core\Institution\Contracts\Term;
use Acadly\Kernel\Blueprint\Profile\ProfileBlueprint;

return ProfileBlueprint::make('SECONDARY@2026.1')
    ->title('Secondary')
    ->summary('Forms 1 to 4. Streams and subject teachers. Marks and report cards.')
    ->extends('SCHOOL_BASE@2026.1')
    ->term(Term::Learner, 'Student', 'Students')
    ->term(Term::Level, 'Form', 'Forms')
    ->term(Term::Group, 'Stream', 'Streams')
    ->levels(['F1' => 'Form 1', 'F2' => 'Form 2', 'F3' => 'Form 3', 'F4' => 'Form 4'])
    ->grouping(Grouping::Streams)
    ->subject('ENG', 'English')
    ->subject('CHI', 'Chichewa')
    ->subject('MAT', 'Mathematics')
    ->subject('BIO', 'Biology')
    ->subject('PHS', 'Physical Science')
    ->subject('AGR', 'Agriculture', false)
    ->subject('GEO', 'Geography', false)
    ->subject('HIS', 'History', false)
    ->subject('SOS', 'Social Studies', false)
    ->subject('LSK', 'Life Skills', false)
    ->subject('BK', 'Bible Knowledge', false)
    ->subject('CST', 'Computer Studies', false, ['F3', 'F4']);
