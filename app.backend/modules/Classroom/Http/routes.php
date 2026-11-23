<?php

declare(strict_types=1);

use Acadly\Modules\Classroom\Http\ClassroomController;
use Acadly\Modules\Classroom\Http\StudentClassroomController;
use Illuminate\Support\Facades\Route;

// The student portal's courses (D-104).
Route::middleware(['auth:sanctum', 'module:classroom'])->prefix('student/classroom')->name('student.classroom.')->group(function (): void {
    Route::get('/courses', [StudentClassroomController::class, 'courses'])->name('courses');
    Route::get('/courses/{id}', [StudentClassroomController::class, 'course'])->whereUuid('id')->name('course');
    Route::get('/tasks/{id}', [StudentClassroomController::class, 'task'])->whereUuid('id')->name('task');
    Route::post('/tasks/{id}/hand-in', [StudentClassroomController::class, 'handIn'])->whereUuid('id')->middleware('throttle:30,1')->name('hand-in');
    Route::post('/tasks/{id}/withdraw', [StudentClassroomController::class, 'withdraw'])->whereUuid('id')->name('withdraw');
    Route::get('/due', [StudentClassroomController::class, 'due'])->name('due');
    Route::post('/announcements/{id}/read', [StudentClassroomController::class, 'read'])->whereUuid('id')->name('read');
    Route::get('/files/{id}', [StudentClassroomController::class, 'file'])->whereUuid('id')->middleware('throttle:60,1')->name('file');
});

Route::middleware(['auth:sanctum', 'module:classroom'])->prefix('classroom')->name('classroom.')->group(function (): void {
    Route::get('/learners/{learner}', [ClassroomController::class, 'learner'])->whereUuid('learner')->name('learner');

    Route::prefix('{division}')->whereUuid('division')->group(function (): void {
        Route::get('/courses', [ClassroomController::class, 'courses'])->name('courses');
        Route::get('/courses/{id}', [ClassroomController::class, 'course'])->whereUuid('id')->name('course');
        Route::post('/courses/{id}/resources', [ClassroomController::class, 'addResource'])->whereUuid('id')->name('resources.add');
        Route::post('/resources/{id}/remove', [ClassroomController::class, 'removeResource'])->whereUuid('id')->name('resources.remove');
        Route::post('/courses/{id}/announcements', [ClassroomController::class, 'announce'])->whereUuid('id')->name('announce');
        Route::put('/courses/{id}/share', [ClassroomController::class, 'share'])->whereUuid('id')->name('share');
        Route::post('/courses/{id}/tasks', [ClassroomController::class, 'saveTask'])->whereUuid('id')->name('tasks.save');
        Route::get('/courses/{id}/assessment', [ClassroomController::class, 'assessment'])->whereUuid('id')->name('assessment');
        Route::post('/tasks/{id}/files', [ClassroomController::class, 'addTaskFile'])->whereUuid('id')->name('tasks.files');
        Route::get('/tasks/{id}', [ClassroomController::class, 'task'])->whereUuid('id')->name('task');
        Route::get('/tasks/{id}/work/{learner}', [ClassroomController::class, 'work'])->whereUuid('id')->whereUuid('learner')->name('work');
        Route::post('/tasks/{id}/marks', [ClassroomController::class, 'mark'])->whereUuid('id')->name('mark');
        Route::post('/tasks/{id}/release', [ClassroomController::class, 'release'])->whereUuid('id')->name('release');
    });
});
