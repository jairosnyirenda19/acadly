<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Attendance: tables and routes. Every route needs the module on. */
final class AttendanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
