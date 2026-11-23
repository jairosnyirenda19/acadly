<?php

declare(strict_types=1);

namespace Acadly\Modules\Results;

use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Modules\Results\Application\ResultsDashboard;
use Acadly\Modules\Results\Application\ResultsParts;
use Acadly\Modules\Results\Application\ResultsToday;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Results: tables, routes, its part of the learner record, Today and the dashboards. */
final class ResultsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ResultsParts::class);
        $this->app->tag([ResultsParts::class], LearnerRecordPart::TAG);
        $this->app->tag([ResultsToday::class], TodayItems::TAG);
        $this->app->tag([ResultsDashboard::class], DashboardPanels::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
