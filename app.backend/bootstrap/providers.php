<?php

use Acadly\Core\Academics\AcademicsServiceProvider;
use Acadly\Core\Institution\InstitutionServiceProvider;
use Acadly\Core\Setup\SetupServiceProvider;
use Acadly\Kernel\KernelServiceProvider;
use Acadly\Kernel\Module\ModuleServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    KernelServiceProvider::class,
    InstitutionServiceProvider::class,
    AcademicsServiceProvider::class,
    SetupServiceProvider::class,
];
