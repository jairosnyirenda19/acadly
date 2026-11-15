<?php

declare(strict_types=1);

namespace Acadly\Core\Setup;

use Acadly\Core\Setup\Application\SetupProgress;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** School setup: the steps, the staff list and opening the school. */
final class SetupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(SetupProgress::class);
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
