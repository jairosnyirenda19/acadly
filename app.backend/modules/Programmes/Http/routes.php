<?php

declare(strict_types=1);

use Acadly\Modules\Programmes\Http\ProgrammesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:programmes'])->prefix('programmes/{division}')->whereUuid('division')->name('programmes.')->group(function (): void {
    Route::get('/structure', [ProgrammesController::class, 'structure'])->name('structure');
    Route::put('/faculties', [ProgrammesController::class, 'saveFaculties'])->name('faculties.save');
    Route::post('/departments', [ProgrammesController::class, 'saveDepartment'])->name('departments.save');
    Route::get('/catalogue', [ProgrammesController::class, 'catalogue'])->name('catalogue');
    Route::post('/courses', [ProgrammesController::class, 'saveCourse'])->name('courses.save');
    Route::post('/programmes', [ProgrammesController::class, 'saveProgramme'])->name('programmes.save');
    Route::get('/programmes/{id}', [ProgrammesController::class, 'programme'])->whereUuid('id')->name('programmes.show');
    Route::post('/programmes/{id}/versions', [ProgrammesController::class, 'newVersion'])->whereUuid('id')->name('versions.store');
    Route::put('/versions/{id}', [ProgrammesController::class, 'saveDraft'])->whereUuid('id')->name('versions.save');
    Route::post('/versions/{id}/release', [ProgrammesController::class, 'release'])->whereUuid('id')->name('versions.release');
    Route::get('/offerings', [ProgrammesController::class, 'offerings'])->name('offerings');
    Route::post('/offerings/{period}/prepare', [ProgrammesController::class, 'prepare'])->whereUuid('period')->name('offerings.prepare');
    Route::post('/offerings/{period}/groups', [ProgrammesController::class, 'addGroup'])->whereUuid('period')->name('offerings.group');
    Route::put('/offering/{id}', [ProgrammesController::class, 'saveOffering'])->whereUuid('id')->name('offerings.save');
});
