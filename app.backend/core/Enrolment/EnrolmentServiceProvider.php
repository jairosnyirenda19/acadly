<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment;

use Acadly\Core\Enrolment\Application\Directory;
use Acadly\Core\Enrolment\Application\LearnerPlaces;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Learners: tables, routes, and where each learner is. */
final class EnrolmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(LearnerPlaces::class);
        $this->app->bind(EnrolmentDirectory::class, Directory::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
