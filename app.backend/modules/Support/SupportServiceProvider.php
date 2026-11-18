<?php

declare(strict_types=1);

namespace Acadly\Modules\Support;

use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Modules\Support\Application\SupportRecordPart;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Support: tables and routes. Every route needs the module on. */
final class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([SupportRecordPart::class], LearnerRecordPart::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
