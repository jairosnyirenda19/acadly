<?php

declare(strict_types=1);

use Acadly\Core\Institution\Contracts\Grouping;
use Acadly\Core\Institution\Contracts\PeriodModel;
use Acadly\Core\Institution\Contracts\Term;
use Acadly\Kernel\Blueprint\Profile\ProfileBlueprint;
use Acadly\Modules\Programmes\Contracts\ProgrammesSlot;
use Acadly\Modules\Programmes\Contracts\StudyShape;

/*
 * D-097. A college or university. A separate blueprint: it extends nothing,
 * because almost all of its shape differs from a school's (02 §2.3).
 * Courses come from the Programmes module, not from starter subjects.
 */
return ProfileBlueprint::make('TERTIARY@2026.1')
    ->title('College')
    ->summary('Faculties, programmes and courses. Two semesters a year. Students register for courses each semester.')
    ->term(Term::Learner, 'Student', 'Students')
    ->term(Term::Level, 'Year of study', 'Years of study')
    ->term(Term::Group, 'Group', 'Groups')
    ->term(Term::Period, 'Semester', 'Semesters')
    ->term(Term::Subject, 'Course', 'Courses')
    ->periods(PeriodModel::Semesters, 2)
    ->levels(['Y1' => 'Year 1', 'Y2' => 'Year 2', 'Y3' => 'Year 3', 'Y4' => 'Year 4', 'Y5' => 'Year 5'])
    ->grouping(Grouping::Streams)
    ->bind(ProgrammesSlot::Shape, StudyShape::Programmes)
    // The parts of a college (D-099): shared ones and its own. Registers, marks and pick-up belong to the school divisions.
    ->modules(['fees', 'conduct', 'support', 'programmes', 'news', 'lectures']);
