<?php

use Acadly\Kernel\KernelServiceProvider;
use Acadly\Kernel\Module\ModuleServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    KernelServiceProvider::class,
];
