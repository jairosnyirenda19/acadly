<?php

declare(strict_types=1);

namespace Acadly\Modules\News;

use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Modules\News\Application\NewsToday;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** News: tables and routes. Every route needs the module on. */
final class NewsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([NewsToday::class], TodayItems::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
