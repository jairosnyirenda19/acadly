<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom;

use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Modules\Classroom\Application\ClassroomDashboard;
use Acadly\Modules\Classroom\Application\ClassroomParts;
use Acadly\Modules\Classroom\Application\ClassroomToday;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Classroom: tables, routes, its part of the learner record, Today and the dashboards. */
final class ClassroomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ClassroomParts::class);
        $this->app->tag([ClassroomParts::class], LearnerRecordPart::TAG);
        $this->app->tag([ClassroomToday::class], TodayItems::TAG);
        $this->app->tag([ClassroomDashboard::class], DashboardPanels::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
