<?php

declare(strict_types=1);

use Acadly\Core\Academics\Http\AcademicsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('academics')->name('academics.')->group(function (): void {
    Route::get('/', [AcademicsController::class, 'show'])->name('show');
    Route::put('/year', [AcademicsController::class, 'year'])->name('year');
    Route::put('/terms', [AcademicsController::class, 'terms'])->name('terms');
    Route::put('/classes', [AcademicsController::class, 'classes'])->name('classes');
});
