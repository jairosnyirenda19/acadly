<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease;

use Acadly\Core\Enrolment\Contracts\FamilyLine;
use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Modules\SafeRelease\Application\FamilyPickup;
use Acadly\Modules\SafeRelease\Application\PickupRecordPart;
use Acadly\Modules\SafeRelease\Application\PickupToday;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Safe pick-up: tables and routes. Every route needs the module on. */
final class SafeReleaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([PickupRecordPart::class], LearnerRecordPart::TAG);
        $this->app->tag([FamilyPickup::class], FamilyLine::TAG);
        $this->app->tag([PickupToday::class], TodayItems::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
