<?php

declare(strict_types=1);

use Acadly\Modules\Attendance\Http\RegistersController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:attendance'])->prefix('registers')->name('registers.')->group(function (): void {
    Route::get('/', [RegistersController::class, 'index'])->name('index');
    Route::get('/class/{id}', [RegistersController::class, 'show'])->whereUuid('id')->name('show');
    Route::post('/class/{id}', [RegistersController::class, 'submit'])->whereUuid('id')->name('submit');
    Route::post('/{register}/corrections', [RegistersController::class, 'requestCorrection'])->whereUuid('register')->name('corrections.store');
    Route::post('/corrections/{id}/decide', [RegistersController::class, 'decide'])->whereUuid('id')->name('corrections.decide');
});
