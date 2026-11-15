<?php

declare(strict_types=1);

use Acadly\Core\Academics\Http\AcademicsController;
use Acadly\Core\Academics\Http\SubjectsController;
use Acadly\Core\Academics\Http\TeachingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('academics')->name('academics.')->group(function (): void {
    Route::get('/', [AcademicsController::class, 'show'])->name('show');
    Route::put('/year', [AcademicsController::class, 'year'])->name('year');
    Route::put('/terms', [AcademicsController::class, 'terms'])->name('terms');
    Route::put('/classes', [AcademicsController::class, 'classes'])->name('classes');

    Route::get('/subjects', [SubjectsController::class, 'index'])->name('subjects.index');
    Route::post('/subjects/start', [SubjectsController::class, 'start'])->name('subjects.start');
    Route::post('/subjects', [SubjectsController::class, 'store'])->name('subjects.store');
    Route::put('/subjects/{id}', [SubjectsController::class, 'update'])->whereUuid('id')->name('subjects.update');
    Route::post('/subjects/{id}/retire', [SubjectsController::class, 'retire'])->whereUuid('id')->name('subjects.retire');

    Route::get('/teaching', [TeachingController::class, 'index'])->name('teaching.index');
    Route::put('/teaching', [TeachingController::class, 'give'])->name('teaching.give');
    Route::post('/teaching/copy', [TeachingController::class, 'copy'])->name('teaching.copy');
});
