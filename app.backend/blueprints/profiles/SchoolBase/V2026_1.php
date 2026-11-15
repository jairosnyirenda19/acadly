<?php

declare(strict_types=1);

use Acadly\Core\Institution\Contracts\PeriodModel;
use Acadly\Core\Institution\Contracts\Term;
use Acadly\Kernel\Blueprint\Profile\ProfileBlueprint;

/** What every school level shares: three terms a year. Cannot be pinned on a division. */
return ProfileBlueprint::abstract('SCHOOL_BASE@2026.1')
    ->title('School')
    ->term(Term::Period, 'Term', 'Terms')
    ->term(Term::Group, 'Class', 'Classes')
    ->periods(PeriodModel::Terms, 3);
