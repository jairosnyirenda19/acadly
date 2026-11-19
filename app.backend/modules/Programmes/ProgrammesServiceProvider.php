<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes;

use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Modules\Programmes\Application\ProgrammesRecordPart;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Programmes: tables and routes. Every route needs the module on. */
final class ProgrammesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([ProgrammesRecordPart::class], LearnerRecordPart::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
