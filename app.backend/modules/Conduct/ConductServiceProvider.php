<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct;

use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Modules\Conduct\Application\ConductRecordPart;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Conduct: tables and routes. Every route needs the module on. */
final class ConductServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([ConductRecordPart::class], LearnerRecordPart::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
