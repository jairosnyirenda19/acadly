<?php

declare(strict_types=1);

namespace Acadly\Core\Institution;

use Acadly\Core\Institution\Application\ApprovalsToday;
use Acadly\Core\Institution\Application\InstitutionDirectory;
use Acadly\Core\Institution\Application\InstitutionScopes;
use Acadly\Core\Institution\Application\ProfileOffering;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Kernel\Access\ScopeTree;
use Acadly\Kernel\Module\ModuleOffering;
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
        $this->app->tag([ApprovalsToday::class], TodayItems::TAG);
        // Each division offers the parts its profile lists (D-099).
        $this->app->scoped(ModuleOffering::class, ProfileOffering::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
