<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct;

use Acadly\Core\Enrolment\Contracts\FamilyLine;
use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Core\Enrolment\Contracts\LearnerSignals;
use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Modules\Attendance\Contracts\AwayByDecision;
use Acadly\Modules\Conduct\Application\ConductDashboard;
use Acadly\Modules\Conduct\Application\ConductFamilyLine;
use Acadly\Modules\Conduct\Application\ConductRecordPart;
use Acadly\Modules\Conduct\Application\ConductSignals;
use Acadly\Modules\Conduct\Application\ConductToday;
use Acadly\Modules\Conduct\Application\Suspensions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Conduct: tables and routes. Every route needs the module on. */
final class ConductServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([ConductRecordPart::class], LearnerRecordPart::TAG);
        $this->app->tag([ConductSignals::class], LearnerSignals::TAG);
        $this->app->tag([ConductDashboard::class], DashboardPanels::TAG);
        $this->app->tag([ConductToday::class], TodayItems::TAG);
        $this->app->tag([Suspensions::class], AwayByDecision::TAG);
        $this->app->tag([ConductFamilyLine::class], FamilyLine::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
