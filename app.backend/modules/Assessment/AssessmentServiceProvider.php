<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment;

use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Kernel\Approvals\ApprovalTopic;
use Acadly\Kernel\Console\SyncStep;
use Acadly\Kernel\Grid\Grids;
use Acadly\Modules\Assessment\Application\AssessmentsGrid;
use Acadly\Modules\Assessment\Application\LearnerResults;
use Acadly\Modules\Assessment\Application\LockApproval;
use Acadly\Modules\Assessment\Application\Plans;
use Acadly\Modules\Assessment\Application\Reach;
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
        $this->app->scoped(LearnerResults::class);
        $this->app->tag([LearnerResults::class], LearnerRecordPart::TAG);
        $this->app->tag([AssessmentsGrid::class], Grids::TAG);
        $this->app->tag([LockApproval::class], ApprovalTopic::TAG);
        $this->app->tag([ScaleSeeds::class], SyncStep::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
