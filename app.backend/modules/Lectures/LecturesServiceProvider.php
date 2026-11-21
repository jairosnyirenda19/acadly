<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures;

use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Modules\Lectures\Application\LectureRecord;
use Acadly\Modules\Lectures\Application\LecturesDashboard;
use Acadly\Modules\Lectures\Application\LecturesToday;
use Acadly\Modules\Lectures\Console\CloseLectures;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Lectures: tables, routes, its parts of the record and dashboards, and the closing of lectures that ended. */
final class LecturesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(LectureRecord::class);
        $this->app->tag([LectureRecord::class], LearnerRecordPart::TAG);
        $this->app->tag([LecturesDashboard::class], DashboardPanels::TAG);
        $this->app->tag([LecturesToday::class], TodayItems::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([CloseLectures::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('lectures:close')->everyFiveMinutes()->withoutOverlapping();
        });
    }
}
