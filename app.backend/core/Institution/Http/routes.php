<?php

declare(strict_types=1);

use Acadly\Core\Institution\Http\DashboardController;
use Acadly\Core\Institution\Http\InstitutionController;
use Illuminate\Support\Facades\Route;

Route::get('/school/logo', [InstitutionController::class, 'publicLogo'])->name('institution.logo.public');

Route::middleware('auth:sanctum')->prefix('institution')->name('institution.')->group(function (): void {
    Route::get('/', [InstitutionController::class, 'show'])->name('show');
    Route::put('/', [InstitutionController::class, 'update'])->name('update');
    Route::post('/logo', [InstitutionController::class, 'logo'])->name('logo');
    Route::put('/look', [InstitutionController::class, 'look'])->name('look');
    Route::put('/levels', [InstitutionController::class, 'levels'])->name('levels');
    Route::post('/campuses', [InstitutionController::class, 'storeCampus'])->name('campuses.store');
    Route::put('/campuses/{id}', [InstitutionController::class, 'updateCampus'])->whereUuid('id')->name('campuses.update');
    Route::put('/offerings', [InstitutionController::class, 'offering'])->name('offerings');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'dashboard'])->name('institution.dashboard');
    Route::get('/today', [DashboardController::class, 'today'])->name('institution.today');
});
