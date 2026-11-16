<?php

declare(strict_types=1);

namespace Acadly\Core\Academics;

use Acadly\Core\Academics\Application\Calendar;
use Acadly\Core\Academics\Application\ClassScopes;
use Acadly\Core\Academics\Application\Timetables;
use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Timetable;
use Acadly\Kernel\Access\ScopeTree;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** The academic calendar and the classes: tables, routes and the calendar others read. */
final class AcademicsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Calendar::class);
        $this->app->bind(AcademicCalendar::class, Calendar::class);
        $this->app->scoped(Timetables::class);
        $this->app->bind(Timetable::class, Timetables::class);
        $this->app->tag(ClassScopes::class, ScopeTree::PARENTS);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
