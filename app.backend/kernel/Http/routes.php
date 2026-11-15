<?php

declare(strict_types=1);

use Acadly\Kernel\Http\HealthController;
use Acadly\Kernel\Http\SchoolController;
use Acadly\Kernel\Identity\Http\MeController;
use Acadly\Kernel\Identity\Http\PasswordResetController;
use Acadly\Kernel\Identity\Http\SignInController;
use Acadly\Kernel\Identity\Http\SignOutController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('kernel.health');
Route::get('/school', SchoolController::class)->name('kernel.school');

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/sign-in', SignInController::class)->name('sign-in');
    Route::post('/sign-out', SignOutController::class)->middleware('auth:sanctum')->name('sign-out');
    Route::post('/forgot-password', [PasswordResetController::class, 'requestLink'])->middleware('throttle:6,1')->name('forgot-password');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1')->name('reset-password');
});

Route::get('/me', MeController::class)->middleware('auth:sanctum')->name('me');
