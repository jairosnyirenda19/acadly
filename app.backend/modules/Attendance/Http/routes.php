<?php

declare(strict_types=1);

use Acadly\Modules\Attendance\Http\LessonsController;
use Acadly\Modules\Attendance\Http\RegistersController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:attendance'])->prefix('registers')->name('registers.')->group(function (): void {
    Route::get('/', [RegistersController::class, 'index'])->name('index');
    Route::get('/levels', [LessonsController::class, 'levels'])->name('levels');
    Route::get('/my-day', [LessonsController::class, 'myDay'])->name('my-day');
    Route::get('/lessons', [LessonsController::class, 'today'])->name('lessons');
    Route::get('/missed', [LessonsController::class, 'missed'])->name('missed');
    Route::get('/lesson/{slot}', [LessonsController::class, 'show'])->whereUuid('slot')->name('lesson.show');
    Route::post('/lesson/{slot}', [LessonsController::class, 'submit'])->whereUuid('slot')->name('lesson.submit');
    Route::post('/away', [LessonsController::class, 'away'])->name('away');
    Route::get('/cover/free', [LessonsController::class, 'free'])->name('cover.free');
    Route::post('/cover', [LessonsController::class, 'ask'])->name('cover.ask');
    Route::get('/class/{id}', [RegistersController::class, 'show'])->whereUuid('id')->name('show');
    Route::post('/class/{id}', [RegistersController::class, 'submit'])->whereUuid('id')->name('submit');
    Route::post('/{register}/corrections', [RegistersController::class, 'requestCorrection'])->whereUuid('register')->name('corrections.store');
    Route::post('/{register}/absences/{learner}/next', [RegistersController::class, 'tellNext'])->whereUuid(['register', 'learner'])->name('absences.next');
    Route::post('/{register}/absences/{learner}/called', [RegistersController::class, 'called'])->whereUuid(['register', 'learner'])->name('absences.called');
    Route::post('/corrections/{id}/decide', [RegistersController::class, 'decide'])->whereUuid('id')->name('corrections.decide');
});
