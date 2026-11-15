<?php

declare(strict_types=1);

namespace Acadly\Core\Institution;

use Acadly\Core\Institution\Application\InstitutionDirectory;
use Acadly\Core\Institution\Application\InstitutionScopes;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\ScopeTree;
use Acadly\Kernel\School\SchoolProfile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** The school's structure: its tables, its routes, and the directory others read. */
final class InstitutionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(InstitutionDirectory::class);
        $this->app->bind(SchoolDirectory::class, InstitutionDirectory::class);
        $this->app->bind(SchoolProfile::class, InstitutionDirectory::class);
        $this->app->tag(InstitutionScopes::class, ScopeTree::PARENTS);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
