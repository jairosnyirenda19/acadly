<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment;

use Acadly\Core\Academics\AcademicsCore;
use Acadly\Core\Enrolment\EnrolmentCore;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;
use Acadly\Modules\Assessment\Contracts\AssessmentAudit;
use Acadly\Modules\Assessment\Contracts\AssessmentDocument;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Notifications\MarksReturned;
use Acadly\Modules\Assessment\Notifications\MarksSubmitted;
use Acadly\Modules\Assessment\Notifications\ResultsPublished;
use Acadly\Modules\Assessment\Notifications\ReviewShared;

/**
 * Assessments, marks and term grades (11 §9, first release): scales, grading
 * plans, setting assessments, the marks sheet with submit, lock and return,
 * term grades with their working, class results and the learner's results,
 * and publishing results with report cards (D-080). Levels that record
 * observations instead: observations by learning area and a progress review
 * for each child each term (D-094).
 */
final class AssessmentModule implements Module
{
    public function key(): string
    {
        return 'assessment';
    }

    public function name(): string
    {
        return 'Assessment';
    }

    public function summary(): string
    {
        return 'Assessments and marks, checked and locked, and term grades worked out from grading plans and scales.';
    }

    public function tier(): Tier
    {
        return Tier::Optional;
    }

    public function requires(): array
    {
        return [InstitutionCore::class, AcademicsCore::class, EnrolmentCore::class];
    }

    public function permissions(): array
    {
        return AssessmentPermission::cases();
    }

    public function auditActions(): array
    {
        return AssessmentAudit::cases();
    }

    public function events(): array
    {
        return [];
    }

    public function subscriptions(): array
    {
        return [];
    }

    public function journeys(): array
    {
        return ['assessment.marks', 'assessment.progress_review'];
    }

    public function topics(): array
    {
        return [MarksSubmitted::class, MarksReturned::class, ResultsPublished::class, ReviewShared::class];
    }

    public function documentKinds(): array
    {
        return AssessmentDocument::cases();
    }
}
