<?php

declare(strict_types=1);

use Acadly\Core\Setup\Http\SetupController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('setup')->name('setup.')->group(function (): void {
    Route::get('/', [SetupController::class, 'show'])->name('show');
    Route::post('/staff', [SetupController::class, 'invite'])->name('staff.invite');
    Route::post('/staff/send', [SetupController::class, 'send'])->name('staff.send');
    Route::post('/open', [SetupController::class, 'open'])->name('open');
});
