<?php

declare(strict_types=1);

use Acadly\Modules\Lectures\Http\LecturesController;
use Acadly\Modules\Lectures\Http\StudentLecturesController;
use Illuminate\Support\Facades\Route;

// The student portal's lectures (D-102).
Route::middleware(['auth:sanctum', 'module:lectures'])->prefix('student/lectures')->name('student.lectures.')->group(function (): void {
    Route::get('/today', [StudentLecturesController::class, 'today'])->name('today');
    Route::get('/week', [StudentLecturesController::class, 'week'])->name('week');
    Route::get('/attendance', [StudentLecturesController::class, 'attendance'])->name('attendance');
    Route::post('/phone/begin', [StudentLecturesController::class, 'phoneBegin'])->middleware('throttle:30,1')->name('phone.begin');
    Route::post('/phone', [StudentLecturesController::class, 'phoneSet'])->middleware('throttle:30,1')->name('phone.set');
    Route::post('/scan', [StudentLecturesController::class, 'scan'])->middleware('throttle:30,1')->name('scan');
});

Route::middleware(['auth:sanctum', 'module:lectures'])->prefix('lectures')->name('lectures.')->group(function (): void {
    Route::get('/learners/{learner}', [LecturesController::class, 'learner'])->whereUuid('learner')->name('learner');
    Route::post('/learners/{learner}/phone/reset', [LecturesController::class, 'resetPhone'])->whereUuid('learner')->name('phone.reset');

    Route::prefix('{division}')->whereUuid('division')->group(function (): void {
        Route::get('/rooms', [LecturesController::class, 'rooms'])->name('rooms');
        Route::post('/rooms', [LecturesController::class, 'saveRoom'])->name('rooms.save');
        Route::put('/areas', [LecturesController::class, 'saveAreas'])->name('areas.save');
        Route::get('/timetable', [LecturesController::class, 'timetable'])->name('timetable');
        Route::post('/timetable/{period}/slots', [LecturesController::class, 'saveSlot'])->whereUuid('period')->name('slots.save');
        Route::post('/timetable/{period}/publish', [LecturesController::class, 'publish'])->whereUuid('period')->name('timetable.publish');
        Route::post('/slots/{id}/remove', [LecturesController::class, 'removeSlot'])->whereUuid('id')->name('slots.remove');
        Route::get('/cover', [LecturesController::class, 'cover'])->name('cover');
        Route::get('/slots/{id}/free', [LecturesController::class, 'free'])->whereUuid('id')->name('cover.free');
        Route::post('/slots/{id}/{action}', [LecturesController::class, 'act'])->whereUuid('id')->whereIn('action', ['away', 'ask', 'accept', 'decline', 'name', 'cancel', 'move'])->name('cover.act');
        Route::get('/my-day', [LecturesController::class, 'myDay'])->name('my-day');
        Route::get('/slots/{id}', [LecturesController::class, 'lecture'])->whereUuid('id')->name('lecture');
        Route::post('/slots/{id}/open', [LecturesController::class, 'open'])->whereUuid('id')->name('lecture.open');
        Route::post('/sessions/{id}/close', [LecturesController::class, 'close'])->whereUuid('id')->name('session.close');
        Route::post('/sessions/{id}/mark', [LecturesController::class, 'mark'])->whereUuid('id')->name('session.mark');
        Route::get('/sessions/{id}/code', [LecturesController::class, 'code'])->whereUuid('id')->middleware('throttle:120,1')->name('session.code');
    });
});
