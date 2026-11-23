<?php

declare(strict_types=1);

use Acadly\Modules\Results\Http\ResultsController;
use Acadly\Modules\Results\Http\StudentResultsController;
use Illuminate\Support\Facades\Route;

// The student portal's results (D-105).
Route::middleware(['auth:sanctum', 'module:results'])->prefix('student/results')->name('student.results.')->group(function (): void {
    Route::get('/', [StudentResultsController::class, 'index'])->name('index');
    Route::get('/courses/{id}', [StudentResultsController::class, 'course'])->whereUuid('id')->name('course');
    Route::get('/attendance', [StudentResultsController::class, 'attendance'])->name('attendance');
    Route::get('/slip', [StudentResultsController::class, 'slip'])->middleware('throttle:60,1')->name('slip');
});

Route::middleware(['auth:sanctum', 'module:results'])->prefix('results')->name('results.')->group(function (): void {
    Route::get('/learners/{learner}', [ResultsController::class, 'learner'])->whereUuid('learner')->name('learner');
    Route::get('/learners/{learner}/slip', [ResultsController::class, 'slip'])->whereUuid('learner')->middleware('throttle:60,1')->name('learner.slip');

    Route::prefix('{division}')->whereUuid('division')->group(function (): void {
        Route::get('/courses', [ResultsController::class, 'courses'])->name('courses');
        Route::get('/courses/{id}', [ResultsController::class, 'sheet'])->whereUuid('id')->name('sheet');
        Route::put('/courses/{id}/marks', [ResultsController::class, 'enter'])->whereUuid('id')->name('marks');
        Route::post('/courses/{id}/submit', [ResultsController::class, 'submit'])->whereUuid('id')->name('submit');
        Route::post('/courses/{id}/bar/{learner}/lift', [ResultsController::class, 'liftBar'])->whereUuid('id')->whereUuid('learner')->name('bar.lift');
        Route::post('/sheets/{id}/moderation', [ResultsController::class, 'moderate'])->whereUuid('id')->name('moderate');
        Route::post('/sheets/{id}/decide', [ResultsController::class, 'decide'])->whereUuid('id')->name('decide');
        Route::post('/sheets/{id}/defer', [ResultsController::class, 'defer'])->whereUuid('id')->name('defer');
        Route::post('/sheets/{id}/return', [ResultsController::class, 'returnSheet'])->whereUuid('id')->name('return');
        Route::post('/sheets/{id}/correct', [ResultsController::class, 'correct'])->whereUuid('id')->name('correct');
        Route::get('/bar', [ResultsController::class, 'bar'])->name('bar');
        Route::get('/board', [ResultsController::class, 'board'])->name('board');
        Route::get('/board/cohort', [ResultsController::class, 'cohort'])->name('board.cohort');
        Route::post('/board/approve', [ResultsController::class, 'approve'])->name('board.approve');
        Route::get('/release', [ResultsController::class, 'releasePage'])->name('release.page');
        Route::post('/release', [ResultsController::class, 'release'])->name('release');
        Route::post('/supplementary', [ResultsController::class, 'supplementary'])->name('supplementary');
        Route::get('/setup', [ResultsController::class, 'setup'])->name('setup');
        Route::post('/setup/scales', [ResultsController::class, 'saveScale'])->name('setup.scale');
        Route::put('/setup/settings', [ResultsController::class, 'saveSettings'])->name('setup.settings');
    });
});
