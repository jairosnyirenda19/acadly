<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module;

use Acadly\Kernel\Console\LintCommand;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LogicException;

/**
 * Builds the registry from the manifests listed in config/acadly.php and
 * mounts the kernel's own routes under /api/v1.
 *
 * The list is explicit on purpose: what is in the build is a decision that
 * shows up in a diff, not a side effect of a folder existing.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, function (Application $app): ModuleRegistry {
            $modules = [];

            foreach (config()->array('acadly.modules') as $class) {
                $module = is_string($class) && class_exists($class) ? $app->make($class) : null;

                if (! $module instanceof Module) {
                    throw new LogicException('config/acadly.php lists something that is not a module manifest.');
                }

                $modules[] = $module;
            }

            return new ModuleRegistry($modules);
        });
    }

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/../Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([LintCommand::class]);
        }
    }
}
