<?php

declare(strict_types=1);

use Acadly\Core\Academics\Http\AcademicsController;
use Acadly\Core\Academics\Http\CalendarFeedController;
use Acadly\Core\Academics\Http\MyTeachingController;
use Acadly\Core\Academics\Http\NextYearController;
use Acadly\Core\Academics\Http\SubjectsController;
use Acadly\Core\Academics\Http\TeachingController;
use Acadly\Core\Academics\Http\TimetableController;
use Illuminate\Support\Facades\Route;

// Your lessons in your calendar (D-089): the private address is the key.
Route::get('/calendar/{token}.ics', [CalendarFeedController::class, 'ics'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:60,1')->name('academics.calendar.feed');
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me/calendar', [CalendarFeedController::class, 'show'])->name('me.calendar');
    Route::post('/me/calendar', [CalendarFeedController::class, 'on'])->middleware('throttle:10,1')->name('me.calendar.on');
    Route::delete('/me/calendar', [CalendarFeedController::class, 'off'])->name('me.calendar.off');
    Route::get('/me/teaching', MyTeachingController::class)->name('me.teaching');
});

Route::middleware('auth:sanctum')->prefix('academics')->name('academics.')->group(function (): void {
    Route::get('/', [AcademicsController::class, 'show'])->name('show');
    Route::put('/year', [AcademicsController::class, 'year'])->name('year');
    Route::put('/terms', [AcademicsController::class, 'terms'])->name('terms');
    Route::put('/classes', [AcademicsController::class, 'classes'])->name('classes');
    Route::get('/next-year', [NextYearController::class, 'show'])->name('next-year');
    Route::put('/next-year', [NextYearController::class, 'save'])->name('next-year.save');

    Route::get('/subjects', [SubjectsController::class, 'index'])->name('subjects.index');
    Route::post('/subjects/start', [SubjectsController::class, 'start'])->name('subjects.start');
    Route::post('/subjects', [SubjectsController::class, 'store'])->name('subjects.store');
    Route::put('/subjects/{id}', [SubjectsController::class, 'update'])->whereUuid('id')->name('subjects.update');
    Route::post('/subjects/{id}/retire', [SubjectsController::class, 'retire'])->whereUuid('id')->name('subjects.retire');

    Route::get('/teaching', [TeachingController::class, 'index'])->name('teaching.index');
    Route::put('/teaching', [TeachingController::class, 'give'])->name('teaching.give');
    Route::post('/teaching/copy', [TeachingController::class, 'copy'])->name('teaching.copy');

    Route::get('/timetable', [TimetableController::class, 'index'])->name('timetable.index');
    Route::put('/timetable/bells', [TimetableController::class, 'bells'])->name('timetable.bells');
    Route::put('/timetable/period', [TimetableController::class, 'period'])->name('timetable.period');
});
