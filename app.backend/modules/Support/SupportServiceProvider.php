<?php

declare(strict_types=1);

namespace Acadly\Modules\Support;

use Acadly\Core\Enrolment\Contracts\FamilyLine;
use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Modules\Support\Application\SupportDashboard;
use Acadly\Modules\Support\Application\SupportFamilyLine;
use Acadly\Modules\Support\Application\SupportRecordPart;
use Acadly\Modules\Support\Application\SupportToday;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Support: tables and routes. Every route needs the module on. */
final class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([SupportFamilyLine::class], FamilyLine::TAG);
        $this->app->tag([SupportRecordPart::class], LearnerRecordPart::TAG);
        $this->app->tag([SupportDashboard::class], DashboardPanels::TAG);
        $this->app->tag([SupportToday::class], TodayItems::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
