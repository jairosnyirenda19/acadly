<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees;

use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Kernel\Access\PlaceGroups;
use Acadly\Kernel\Grid\Grids;
use Acadly\Modules\Fees\Application\BehindGrid;
use Acadly\Modules\Fees\Application\FeeOffices;
use Acadly\Modules\Fees\Application\FeesDashboard;
use Acadly\Modules\Fees\Application\FeesRecordPart;
use Acadly\Modules\Fees\Application\FeesStandingReader;
use Acadly\Modules\Fees\Application\PaymentsGrid;
use Acadly\Modules\Fees\Contracts\FeesStanding;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Fees: tables and routes. Every route needs the module on. */
final class FeesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FeesStanding::class, FeesStandingReader::class);
        // The finance offices are places to work in (D-099).
        $this->app->tag([FeeOffices::class], PlaceGroups::TAG);
        $this->app->tag([FeesRecordPart::class], LearnerRecordPart::TAG);
        $this->app->tag([FeesDashboard::class], DashboardPanels::TAG);
        $this->app->tag([PaymentsGrid::class, BehindGrid::class], Grids::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
