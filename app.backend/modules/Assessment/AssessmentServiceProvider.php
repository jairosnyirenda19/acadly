<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment;

use Acadly\Core\Enrolment\Contracts\DivisionFigures;
use Acadly\Core\Enrolment\Contracts\FamilyLine;
use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Core\Enrolment\Contracts\LearnerSignals;
use Acadly\Core\Enrolment\Contracts\YearEndCheck;
use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Kernel\Approvals\ApprovalTopic;
use Acadly\Kernel\Console\SyncStep;
use Acadly\Kernel\Grid\Grids;
use Acadly\Modules\Assessment\Application\AssessmentsGrid;
use Acadly\Modules\Assessment\Application\AssessmentToday;
use Acadly\Modules\Assessment\Application\AssessmentYearEnd;
use Acadly\Modules\Assessment\Application\FamilyCards;
use Acadly\Modules\Assessment\Application\FamilyReviews;
use Acadly\Modules\Assessment\Application\LearnerResults;
use Acadly\Modules\Assessment\Application\LockApproval;
use Acadly\Modules\Assessment\Application\MarkApproval;
use Acadly\Modules\Assessment\Application\Observations;
use Acadly\Modules\Assessment\Application\PlanApproval;
use Acadly\Modules\Assessment\Application\Plans;
use Acadly\Modules\Assessment\Application\PublishApproval;
use Acadly\Modules\Assessment\Application\Publishing;
use Acadly\Modules\Assessment\Application\Reach;
use Acadly\Modules\Assessment\Application\Requests;
use Acadly\Modules\Assessment\Application\ResultsDashboard;
use Acadly\Modules\Assessment\Application\ResultSignals;
use Acadly\Modules\Assessment\Application\ReviewFigures;
use Acadly\Modules\Assessment\Application\Scales;
use Acadly\Modules\Assessment\Application\ScaleSeeds;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Assessment: tables, seeds, the grid, the record part and routes. Every route needs the module on. */
final class AssessmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Scales::class);
        $this->app->scoped(Plans::class);
        $this->app->scoped(Reach::class);
        $this->app->scoped(Observations::class);
        $this->app->scoped(Publishing::class);
        $this->app->scoped(LearnerResults::class);
        $this->app->tag([LearnerResults::class], LearnerRecordPart::TAG);
        $this->app->tag([ResultSignals::class], LearnerSignals::TAG);
        $this->app->tag([FamilyCards::class, FamilyReviews::class], FamilyLine::TAG);
        $this->app->tag([AssessmentYearEnd::class], YearEndCheck::TAG);
        $this->app->tag([ReviewFigures::class], DivisionFigures::TAG);
        $this->app->tag([ResultsDashboard::class], DashboardPanels::TAG);
        $this->app->tag([AssessmentToday::class], TodayItems::TAG);
        $this->app->tag([AssessmentsGrid::class], Grids::TAG);
        $this->app->tag([LockApproval::class, PublishApproval::class, MarkApproval::class, PlanApproval::class], ApprovalTopic::TAG);
        $this->app->scoped(Requests::class);
        $this->app->tag([ScaleSeeds::class], SyncStep::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
