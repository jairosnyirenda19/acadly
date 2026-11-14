<?php

declare(strict_types=1);

use Acadly\Kernel\Http\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('kernel.health');
